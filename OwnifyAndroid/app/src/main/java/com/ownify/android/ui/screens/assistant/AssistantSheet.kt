package com.ownify.android.ui.screens.assistant

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.ime
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.requiredSize
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.union
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicText
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import com.ownify.android.data.AiAction
import com.ownify.android.data.AiBlock
import com.ownify.android.data.AiCopy
import com.ownify.android.data.AiMessage
import com.ownify.android.data.AiNotice
import com.ownify.android.data.AiSpan
import com.ownify.android.data.AiView
import com.ownify.android.data.OwnifyAssistant
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.BtnLook
import com.ownify.android.ui.design.CardStyle
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalGround
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.Pill
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.blurring
import com.ownify.android.ui.design.loopValue
import com.ownify.android.ui.design.press
import com.ownify.android.ui.design.recordBackdrop
import com.ownify.android.ui.design.rememberBackdrop
import com.ownify.android.ui.screens.GlassOrb
import com.ownify.android.ui.theme.InButton
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType

/**
 * Ownify AI (pages/ai.php): a sheet pulled up over the current page. Its top
 * is where it is pulled down again; under it one of three screens, as on the
 * website:
 *
 *   consent    before anything goes to Gemini: what happens, yes or not now
 *   declined   nothing is sent, and the way back to yes
 *   chat       the conversation, the field under it; the conversations in a
 *              pane over it
 *
 * The state is [OwnifyAssistant]'s, not this composable's: the sheet is not
 * composed while it is closed, and a question on its way must not be lost by
 * pulling it down.
 */
@Composable
fun AssistantSheet(ai: AiCopy) {
    val shell = LocalShell.current
    val context = LocalContext.current
    val session = ai.session
    val view = OwnifyAssistant.view(session)

    // The pages were read again (Instellingen → Privacy changed the answer).
    LaunchedEffect(session.consent) { OwnifyAssistant.follow(session) }

    // Read from the server the first time the sheet opens: the conversation,
    // and the answer to the consent question as the account has it now — one
    // given on the website counts here too.
    LaunchedEffect(shell.aiOpen, OwnifyAssistant.loaded) {
        if (shell.aiOpen && !OwnifyAssistant.loaded) {
            OwnifyAssistant.load(context, ai)
        }
    }

    Column(
        Modifier
            .fillMaxSize()
            // Above the keyboard, and above the navigation bar when there is none.
            .windowInsetsPadding(WindowInsets.ime.union(WindowInsets.navigationBars).only(WindowInsetsSides.Bottom))
            .semantics { contentDescription = ai.title }
    ) {
        SheetTop(ai, view)

        when (view) {
            AiView.Consent -> ConsentScreen(ai, Modifier.weight(1f))
            AiView.Declined -> DeclinedScreen(ai, Modifier.weight(1f))
            AiView.Chat -> Box(Modifier.weight(1f).fillMaxWidth()) {
                Column(Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally) {
                    Conversation(ai, Modifier.weight(1f))
                    Composer(ai)
                }
                if (OwnifyAssistant.historyOpen) HistoryPane(ai)
            }
        }
    }
}

/* ------------------------------------------------------------------ the top */

/** `.ai-top`: the grabber, the close pill, and — in the conversation — new and the list. */
@Composable
private fun SheetTop(ai: AiCopy, view: AiView) {
    val shell = LocalShell.current
    val screen = LocalScreen.current
    val density = LocalDensity.current
    val context = LocalContext.current
    val insets = WindowInsets.safeDrawing.asPaddingValues()

    Column(
        Modifier
            .fillMaxWidth()
            .onGloballyPositioned {
                shell.sheetTopZone = it.boundsInRoot().let { r ->
                    Rect(r.left / density.density, r.top / density.density, r.right / density.density, r.bottom / density.density)
                }
            }
            .padding(top = maxOf(Ownify.Space3, insets.calculateTopPadding()), bottom = Ownify.Space3),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Box(
            Modifier
                .padding(top = Ownify.Space2)
                .size(38.dp, 4.dp)
                .background(Ownify.ink(0.20f), RoundedCornerShape(50))
        )
        Row(Modifier.width(screen.shell).padding(top = Ownify.Space3), verticalAlignment = Alignment.CenterVertically) {
            Pill(ai.closeLabel, onClick = shell::closeAi, icon = OwnifyIcons.chevronDown, contentDescription = ai.closeAria)
            Spacer(Modifier.weight(1f))
            if (view == AiView.Chat) {
                IconPill(OwnifyIcons.plus, ai.newChat) { OwnifyAssistant.startNew(ai) }
                Spacer(Modifier.width(Ownify.Space2))
                IconPill(OwnifyIcons.chat, ai.history, active = OwnifyAssistant.historyOpen) {
                    if (OwnifyAssistant.historyOpen) OwnifyAssistant.historyOpen = false
                    else OwnifyAssistant.openHistory(context, ai)
                }
            }
        }
    }
}

/** `.pill--icon`: the close pill's glass, round, an icon of 18. */
@Composable
private fun IconPill(icon: ImageVector, label: String, active: Boolean = false, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Box(
            Modifier
                .press(interaction)
                .size(42.dp)
                .clip(CircleShape)
                .background(Ownify.GlassSoft)
                .border(1.dp, if (active) Ownify.Health.copy(alpha = 0.45f) else Ownify.GlassBorderSoft, CircleShape)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics {
                    contentDescription = label
                    if (active) stateDescription = "Open"
                },
            contentAlignment = Alignment.Center
        ) {
            JIcon(icon, size = 18.dp, color = if (active) Ownify.TextPrimary else Ownify.TextSecondary)
        }
    }
}

/* ------------------------------------------------------------- consent */

private val PrimaryLook: BtnLook
    get() = BtnLook(border = Ownify.Health.copy(alpha = 0.55f), fill = Ownify.Health.copy(alpha = 0.26f))

@Composable
private fun ConsentScreen(ai: AiCopy, modifier: Modifier) {
    val screen = LocalScreen.current
    val context = LocalContext.current
    val consent = ai.consent
    val busy = OwnifyAssistant.consentBusy

    Column(modifier.fillMaxWidth().verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally) {
        Column(Modifier.width(screen.shell).padding(top = Ownify.Space2, bottom = Ownify.Space5), horizontalAlignment = Alignment.CenterHorizontally) {
            AssistantOrb(small = true)
            T(consent.title, JStyle.Section, Modifier.padding(top = Ownify.Space4).semantics { heading() }, align = TextAlign.Center)
            T(consent.intro, JStyle.Lede, Modifier.padding(top = Ownify.Space2), align = TextAlign.Center)

            JCard(
                Modifier.fillMaxWidth().padding(top = Ownify.Space4),
                padding = PaddingValues(Ownify.Space4)
            ) {
                consent.points.forEachIndexed { i, point ->
                    Row(Modifier.padding(top = if (i == 0) 0.dp else Ownify.Space3)) {
                        Box(Modifier.padding(top = 8.dp, end = Ownify.Space3).size(5.dp).background(Ownify.Health, CircleShape))
                        T(point, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary))
                    }
                }
            }

            OwnifyAssistant.consentError?.let {
                T(it, OwnifyType.style(Ownify.FsSmall, color = Ownify.Attention), Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
            }

            Row(Modifier.fillMaxWidth().padding(top = Ownify.Space5), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                Btn(consent.accept, onClick = { OwnifyAssistant.decide(context, ai, accept = true) }, Modifier.weight(1f), enabled = !busy, look = PrimaryLook)
                Btn(consent.decline, onClick = { OwnifyAssistant.decide(context, ai, accept = false) }, Modifier.weight(1f), enabled = !busy)
            }
            T(consent.footer, JStyle.Tiny, Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}

@Composable
private fun DeclinedScreen(ai: AiCopy, modifier: Modifier) {
    val screen = LocalScreen.current

    Column(modifier.fillMaxWidth().verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally) {
        Column(Modifier.width(screen.shell).padding(top = Ownify.Space2, bottom = Ownify.Space5), horizontalAlignment = Alignment.CenterHorizontally) {
            AssistantOrb(small = true, still = true)
            T(ai.declined.title, JStyle.Section, Modifier.padding(top = Ownify.Space4).semantics { heading() }, align = TextAlign.Center)
            T(ai.declined.body, JStyle.Lede, Modifier.padding(top = Ownify.Space2), align = TextAlign.Center)
            Btn(ai.declined.review, onClick = { OwnifyAssistant.reviewing = true }, Modifier.padding(top = Ownify.Space5).widthIn(min = 220.dp))
            T(ai.consent.footer, JStyle.Tiny, Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}

/* -------------------------------------------------------- the conversation */

@Composable
private fun Conversation(ai: AiCopy, modifier: Modifier) {
    val screen = LocalScreen.current
    val a = OwnifyAssistant
    val messages = a.messages
    val sending = a.sending
    val list = rememberLazyListState()
    val count = messages.size + if (sending != null) 2 else 0

    // To the very end, as the website scrolls to it — the newest message's
    // bottom in view, however tall it is (the list stops at its end).
    LaunchedEffect(count, messages.lastOrNull()?.action?.state, a.notice) {
        if (count > 0) list.animateScrollToItem(count - 1, scrollOffset = 100_000)
    }

    if (messages.isEmpty() && sending == null) {
        if (a.loading && !a.loaded) {
            Box(modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { Thinking(ai) }
        } else {
            EmptyState(ai, modifier)
        }
        return
    }

    LazyColumn(
        modifier.fillMaxWidth(),
        state = list,
        horizontalAlignment = Alignment.CenterHorizontally,
        contentPadding = PaddingValues(top = Ownify.Space2, bottom = Ownify.Space4),
        verticalArrangement = Arrangement.spacedBy(Ownify.Space4)
    ) {
        items(messages, key = { "m" + it.id }) { message ->
            Box(Modifier.width(screen.shell)) { MessageView(ai, message) }
        }
        if (sending != null) {
            item(key = "sending") { Box(Modifier.width(screen.shell)) { UserBubble(sending) } }
            item(key = "thinking") { Box(Modifier.width(screen.shell)) { AssistantPane { Thinking(ai) } } }
        }
    }
}

/** `.ai-empty`: the orb, what the assistant is for, three questions to start with. */
@Composable
private fun EmptyState(ai: AiCopy, modifier: Modifier) {
    val screen = LocalScreen.current
    val context = LocalContext.current
    val hasData = OwnifyAssistant.hasData ?: ai.session.hasData

    Column(
        modifier.fillMaxWidth().verticalScroll(rememberScrollState()),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Column(Modifier.width(screen.shell).padding(vertical = Ownify.Space4), horizontalAlignment = Alignment.CenterHorizontally) {
            AssistantOrb()
            T(
                ai.empty.title,
                OwnifyType.style(screen.fsBrand, FontWeight.SemiBold, tracking = (-0.03).em, lineHeight = 1.25.em),
                Modifier.padding(top = Ownify.Space6).semantics { heading() },
                align = TextAlign.Center
            )
            T(ai.empty.body, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space2), align = TextAlign.Center)
            if (!hasData && ai.empty.noData.isNotEmpty()) {
                T(ai.empty.noData, JStyle.Tiny, Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
            }
            Column(
                Modifier.padding(top = Ownify.Space5).widthIn(max = 360.dp).fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
            ) {
                ai.empty.suggestions.forEach { suggestion ->
                    Suggestion(suggestion) { OwnifyAssistant.ask(context, ai, suggestion) }
                }
            }
        }
    }
}

/** `.ai-suggestion`: a quiet glass chip, one question per line. */
@Composable
private fun Suggestion(text: String, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(Ownify.RadiusMd)
        Box(
            Modifier
                .press(interaction)
                .fillMaxWidth()
                .heightIn(min = 44.dp)
                .clip(shape)
                .background(Ownify.GlassSoft)
                .border(1.dp, Ownify.GlassBorderSoft, shape)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .padding(horizontal = Ownify.Space4, vertical = Ownify.Space3),
            contentAlignment = Alignment.CenterStart
        ) {
            T(text, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary))
        }
    }
}

@Composable
private fun MessageView(ai: AiCopy, message: AiMessage) {
    when (message.role) {
        "user" -> UserBubble(message.text)
        "system" -> SystemNote(message.text)
        else -> AssistantPane {
            Blocks(message)
            message.action?.let { ActionCard(ai, message.id, it) }
        }
    }
}

/** `.ai-msg--user`: the person's words on the right, in the app's green. */
@Composable
private fun UserBubble(text: String) {
    val screen = LocalScreen.current
    val shape = RoundedCornerShape(20.dp, 20.dp, 6.dp, 20.dp)
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
        Box(
            Modifier
                .widthIn(max = screen.shell * 0.85f)
                .clip(shape)
                .background(Brush.verticalGradient(listOf(Ownify.Health.copy(alpha = 0.22f), Ownify.Health.copy(alpha = 0.14f))))
                .border(1.dp, Ownify.Health.copy(alpha = 0.28f), shape)
                .padding(horizontal = Ownify.Space4, vertical = Ownify.Space3)
        ) {
            T(text, OwnifyType.style(Ownify.FsBody, lineHeight = 1.45.em))
        }
    }
}

/** `.ai-msg--assistant`: glass across the column, read like a note. */
@Composable
private fun AssistantPane(content: @Composable () -> Unit) {
    val shape = RoundedCornerShape(6.dp, 20.dp, 20.dp, 20.dp)
    Column(
        Modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Brush.linearGradient(listOf(Ownify.SurfaceQuietFrom, Ownify.SurfaceQuietTo)))
            .border(1.dp, Ownify.GlassBorderSoft, shape)
            .padding(Ownify.Space4),
        verticalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        content()
    }
}

/** A note from Ownify itself — "Doel toegevoegd" — centred and small. */
@Composable
private fun SystemNote(text: String) {
    val shape = RoundedCornerShape(50)
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
        Row(
            Modifier
                .widthIn(max = LocalScreen.current.shell * 0.9f)
                .clip(shape)
                .background(Ownify.GlassSoft)
                .border(1.dp, Ownify.GlassBorderSoft, shape)
                .padding(horizontal = Ownify.Space3, vertical = Ownify.Space2),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)
        ) {
            JIcon(OwnifyIcons.check, size = 14.dp, color = Ownify.Health)
            T(text, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary, lineHeight = 1.4.em), align = TextAlign.Center)
        }
    }
}

private val AnswerStyle: TextStyle
    get() = OwnifyType.style(Ownify.FsBody, lineHeight = 1.55.em)

/** The server's blocks: paragraphs, a short heading, bullets and steps — text and bold only. */
@Composable
private fun Blocks(message: AiMessage) {
    if (message.blocks.isEmpty()) {
        T(message.text, AnswerStyle)
        return
    }

    message.blocks.forEach { block ->
        when (block) {
            is AiBlock.Paragraph -> Rich(block.spans, AnswerStyle)
            is AiBlock.Heading -> Rich(block.spans, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextSecondary))
            is AiBlock.Bullets -> ListItems(block.items) { "•" }
            is AiBlock.Steps -> ListItems(block.items) { "${it + 1}." }
        }
    }
}

@Composable
private fun ListItems(items: List<List<AiSpan>>, mark: (Int) -> String) {
    Column(verticalArrangement = Arrangement.spacedBy(Ownify.Space1)) {
        items.forEachIndexed { i, spans ->
            Row {
                T(mark(i), AnswerStyle.copy(color = Ownify.TextMuted), Modifier.width(22.dp))
                Rich(spans, AnswerStyle)
            }
        }
    }
}

@Composable
private fun Rich(spans: List<AiSpan>, style: TextStyle) {
    BasicText(
        buildAnnotatedString {
            spans.forEach { span ->
                if (span.bold) withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) { append(span.text) }
                else append(span.text)
            }
        },
        style = style.copy(letterSpacing = 0.sp)
    )
}

/** `.ai-action`: a change the assistant prepared — what it is, and yes or not now. */
@Composable
private fun ActionCard(ai: AiCopy, messageId: Long, action: AiAction) {
    val context = LocalContext.current
    val done = action.state == "done"
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    val busy = OwnifyAssistant.answering != null

    Column(
        Modifier
            .fillMaxWidth()
            .clip(shape)
            .background(if (done) Ownify.GlassSoft else Ownify.Health.copy(alpha = 0.08f))
            .border(1.dp, if (done) Ownify.GlassBorderSoft else Ownify.Health.copy(alpha = 0.32f), shape)
            .padding(Ownify.Space4)
    ) {
        T(action.title, OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold))
        if (action.summary.isNotEmpty()) {
            T(action.summary, OwnifyType.style(Ownify.FsTiny, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space1))
        }
        if (action.pending) {
            Row(Modifier.padding(top = Ownify.Space3), horizontalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                Btn(action.confirm, onClick = { OwnifyAssistant.resolve(context, ai, messageId, confirm = true) }, enabled = !busy, look = PrimaryLook)
                Btn(action.decline, onClick = { OwnifyAssistant.resolve(context, ai, messageId, confirm = false) }, enabled = !busy)
            }
        } else {
            action.status?.let {
                T(
                    it,
                    OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, if (done) Ownify.Health else Ownify.TextMuted),
                    Modifier.padding(top = Ownify.Space2)
                )
            }
        }
    }
}

/** Three dots while the answer is on its way. */
@Composable
private fun Thinking(ai: AiCopy) {
    val still = LocalStillMotion.current
    val phase = loopValue(0f, 3f, infiniteRepeatable(tween(1_200, easing = LinearEasing)))
    Row(
        Modifier
            .height(20.dp)
            .semantics {
                contentDescription = ai.thinking
                liveRegion = LiveRegionMode.Polite
            },
        horizontalArrangement = Arrangement.spacedBy(5.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        repeat(3) { i ->
            val lit = if (still) 0.6f else (1f - ((phase - i + 3f) % 3f).coerceIn(0f, 1f)).coerceIn(0.3f, 1f)
            Box(Modifier.size(7.dp).alpha(lit).background(Ownify.TextMuted, CircleShape))
        }
    }
}

/* -------------------------------------------------------------- the field */

/** `.ai-composer`: why it cannot answer (if so), the field, and what is left today. */
@Composable
private fun Composer(ai: AiCopy) {
    val screen = LocalScreen.current
    val context = LocalContext.current
    val a = OwnifyAssistant
    val usage = a.usage(ai.session)
    val out = usage.out
    val sending = a.sending != null
    val unavailable = (a.available ?: ai.session.available) == false

    val notice = a.notice
        ?: if (unavailable) AiNotice(ai.session.notice ?: ai.error("unavailable")) else null
        ?: if (out) AiNotice(ai.error("limit")) else null

    Column(Modifier.width(screen.shell).padding(top = Ownify.Space3, bottom = Ownify.Space2)) {
        notice?.let { NoticeCard(ai, it) }

        val shape = RoundedCornerShape(26.dp)
        Row(
            Modifier
                .fillMaxWidth()
                .clip(shape)
                .background(Ownify.Glass)
                .border(1.dp, Ownify.GlassBorder, shape)
                .padding(start = Ownify.Space4, end = 6.dp, top = 6.dp, bottom = 6.dp),
            verticalAlignment = Alignment.Bottom
        ) {
            val style = OwnifyType.style(16.sp, color = Ownify.TextPrimary, lineHeight = 1.4.em, tracking = 0.sp)
            BasicTextField(
                value = a.draft,
                onValueChange = { a.draft = it.take(2000) },
                modifier = Modifier
                    .weight(1f)
                    .heightIn(min = 40.dp)
                    .padding(vertical = 9.dp)
                    .semantics { contentDescription = ai.composerAria },
                enabled = !out,
                textStyle = style,
                maxLines = 5,
                cursorBrush = SolidColor(Ownify.TextPrimary),
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
                decorationBox = { inner ->
                    Box(contentAlignment = Alignment.CenterStart) {
                        if (a.draft.isEmpty()) T(ai.placeholder, style.copy(color = Ownify.TextMuted), maxLines = 1)
                        inner()
                    }
                }
            )
            SendButton(ai.send, enabled = !out && !sending && a.draft.isNotBlank()) {
                a.ask(context, ai, a.draft)
            }
        }

        val left = ai.remainingText(usage)
        if (left.isNotEmpty()) {
            T(left, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space2), align = TextAlign.Center)
        }
    }
}

@Composable
private fun SendButton(label: String, enabled: Boolean, onClick: () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    Box(
        Modifier
            .press(interaction, enabled = enabled)
            .size(40.dp)
            .clip(CircleShape)
            .background(if (enabled) Ownify.Health else Ownify.fill(0.10f))
            .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
            .semantics { contentDescription = label },
        contentAlignment = Alignment.Center
    ) {
        JIcon(OwnifyIcons.arrowUp, size = 20.dp, color = if (enabled) Color.White else Ownify.TextMuted)
    }
}

/** `.ai-notice`: in the gold, above the field; "Opnieuw proberen" when that can help. */
@Composable
private fun NoticeCard(ai: AiCopy, notice: AiNotice) {
    val context = LocalContext.current
    val shape = RoundedCornerShape(Ownify.RadiusMd)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(bottom = Ownify.Space2)
            .clip(shape)
            .background(Ownify.AttentionWash.copy(alpha = 0.10f))
            .border(1.dp, Ownify.AttentionWash.copy(alpha = 0.32f), shape)
            .padding(horizontal = Ownify.Space4, vertical = Ownify.Space3)
            .semantics { liveRegion = LiveRegionMode.Polite },
        verticalAlignment = Alignment.Top
    ) {
        T(notice.text, OwnifyType.style(Ownify.FsSmall, lineHeight = 1.45.em), Modifier.weight(1f))
        if (notice.retry != null || notice.reload) {
            T(
                ai.retry,
                OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.Health),
                Modifier
                    .padding(start = Ownify.Space3)
                    .clickable(role = Role.Button) {
                        if (notice.retry != null) OwnifyAssistant.ask(context, ai, notice.retry)
                        else OwnifyAssistant.load(context, ai, OwnifyAssistant.conversation?.id)
                    }
            )
        }
    }
}

/* ------------------------------------------------------- the conversations */

/** `.ai-history`: a pane over the conversation, the sheet dimmed behind it. */
@Composable
private fun HistoryPane(ai: AiCopy) {
    val screen = LocalScreen.current
    val context = LocalContext.current
    val a = OwnifyAssistant
    val dismiss = remember { MutableInteractionSource() }

    Box(
        Modifier
            .fillMaxSize()
            .background(Ownify.scrim(0.42f))
            .clickable(dismiss, indication = null) { a.historyOpen = false },
        contentAlignment = Alignment.TopCenter
    ) {
        JCard(
            Modifier
                .padding(top = Ownify.Space2)
                .width(screen.shell)
                .heightIn(max = 520.dp)
                .clickable(remember { MutableInteractionSource() }, indication = null) { },
            style = CardStyle.Default,
            padding = PaddingValues(Ownify.Space4)
        ) {
            Row(Modifier.fillMaxWidth().padding(bottom = Ownify.Space3), verticalAlignment = Alignment.CenterVertically) {
                T(ai.history, OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold), Modifier.weight(1f).semantics { heading() })
                Btn(ai.newChat, onClick = { a.startNew(ai) }, icon = OwnifyIcons.plus)
            }

            if (a.conversations.isEmpty()) {
                T(ai.historyEmpty, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted), Modifier.padding(vertical = Ownify.Space4))
            } else {
                LazyColumn(Modifier.heightIn(max = 400.dp)) {
                    items(a.conversations, key = { "c" + it.id }) { conversation ->
                        var armed by remember(conversation.id) { mutableStateOf(false) }
                        val current = a.conversation?.id == conversation.id
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(Ownify.RadiusSm))
                                .background(if (current) Ownify.Health.copy(alpha = 0.12f) else Color.Transparent),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Column(
                                Modifier
                                    .weight(1f)
                                    .clickable(role = Role.Button) { a.open(context, ai, conversation.id) }
                                    .padding(horizontal = Ownify.Space2, vertical = Ownify.Space3)
                            ) {
                                T(conversation.title, OwnifyType.style(Ownify.FsSmall, FontWeight.Medium), maxLines = 1, ellipsis = true)
                                T(conversation.label, JStyle.Tiny)
                            }
                            if (armed) {
                                Btn(ai.deleteChat + "?", onClick = { a.delete(context, ai, conversation.id) }, look = BtnLook.Final)
                            } else {
                                Box(
                                    Modifier
                                        .size(38.dp)
                                        .clip(CircleShape)
                                        .clickable(role = Role.Button) { armed = true }
                                        .semantics { contentDescription = ai.deleteChat + ": " + conversation.title },
                                    contentAlignment = Alignment.Center
                                ) {
                                    JIcon(OwnifyIcons.trash, size = 18.dp, color = Ownify.TextMuted)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ------------------------------------------------------------------- orb */

/**
 * `.orb`: a dotted ring turning once every 72 s round a green light and a
 * breathing sphere of glass — the dashboard's "no data yet" idiom. [small]
 * over the consent question, [still] once the assistant is off.
 */
@Composable
private fun AssistantOrb(small: Boolean = false, still: Boolean = false) {
    val stillMotion = LocalStillMotion.current || still
    val screen = LocalScreen.current
    val size: Dp = if (small) (screen.width * 0.24f).coerceIn(84.dp, 104.dp) else (screen.width * 0.36f).coerceIn(120.dp, 152.dp)
    val turn = loopValue(0f, 360f, infiniteRepeatable(tween(72_000, easing = LinearEasing)))
    val breathe = loopValue(1f, 1.035f, infiniteRepeatable(tween(3_500, easing = Ownify.Ease), RepeatMode.Reverse))

    val (behind, behindLayer) = rememberBackdrop()
    Box(Modifier.size(size).alpha(if (still) 0.8f else 1f), contentAlignment = Alignment.Center) {
        // What the glass looks through: the light and the ring, recorded.
        Box(Modifier.requiredSize(size * 1.68f).recordBackdrop(behind, behindLayer), contentAlignment = Alignment.Center) {
            if (!still) {
                // .orb__glow — inset -34%
                Canvas(Modifier.size(size * 1.68f)) {
                    drawCircle(
                        Brush.radialGradient(
                            0f to Ownify.Health.copy(alpha = 0.16f),
                            0.26f to Ownify.Health.copy(alpha = 0.08f),
                            0.48f to Ownify.Health.copy(alpha = 0.02f),
                            0.72f to Ownify.Health.copy(alpha = 0f),
                            center = center,
                            radius = this.size.width * 0.70710677f
                        )
                    )
                }
            }
            Canvas(Modifier.size(size)) {
                val unit = this.size.width / 160f
                rotate(if (stillMotion) 0f else turn) {
                    drawCircle(
                        Ownify.ink(if (still) 0.13f else 0.26f),
                        radius = 74f * unit,
                        style = Stroke(3.5f * unit, cap = StrokeCap.Round, pathEffect = PathEffect.dashPathEffect(floatArrayOf(0.5f * unit, 9f * unit)))
                    )
                }
            }
        }
        // .orb__core — inset 16%
        GlassOrb(size * 0.68f, if (stillMotion) 1f else breathe, behind, LocalGround.current)
    }
}
