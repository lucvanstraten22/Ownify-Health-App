package com.ownify.android.ui.screens.assistant

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.animation.expandHorizontally
import androidx.compose.animation.shrinkHorizontally
import androidx.compose.foundation.Image
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.graphics.ColorFilter
import androidx.compose.ui.graphics.ColorMatrix
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.drawscope.withTransform
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.IntSize
import com.ownify.android.R
import com.ownify.android.data.AiConversation
import com.ownify.android.ui.design.BoxShadow
import com.ownify.android.ui.design.PaneStyle
import com.ownify.android.ui.design.Stop
import com.ownify.android.ui.design.cssRadialGradient
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ExperimentalAnimationApi
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.snap
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.TransformOrigin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import com.ownify.android.ui.app.Spinner
import com.ownify.android.ui.design.Pane
import com.ownify.android.ui.design.Panes
import com.ownify.android.ui.design.LocalReducedMotion
import com.ownify.android.ui.design.LocalStillMotion
import com.ownify.android.i18n.trf
import kotlinx.coroutines.delay
import com.ownify.android.i18n.tr
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.tween
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
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
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
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.blurring
import com.ownify.android.ui.design.loopValue
import com.ownify.android.ui.design.press
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

    Box(Modifier.fillMaxSize()) {
        // The sheet's light: green from above, a dawn at the foot.
        AiSky(thinking = OwnifyAssistant.sending != null, modifier = Modifier.fillMaxSize())

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
                    WaitCapsule(Modifier.align(Alignment.TopCenter).padding(top = Ownify.Space1))
                }
            }
        }

        // Gesprekken, over everything — the island it grows out of included.
        if (view == AiView.Chat) HistoryLayer(ai)
    }
}

/* ------------------------------------------------------------------ the top */

/** OwnifyAI's own colours (ai.css `.sheet--ai`): the light, and your bubble. */
private val AiMint = Color(0xFF6EC49C)
private val AiBlue = Color(0xFF4F78D6)
private val AiViolet = Color(0xFF8A62D2)
/** Your words: the green, deep enough for white text to read (4.6 : 1), in both themes. */
private val AiUser = Color(0xFF2F8460)
private val EaseInOutCss = CubicBezierEasing(0.42f, 0f, 0.58f, 1f)

/** The island and the round buttons: the header's floating glass, a touch more solid. */
private val IslandPane: PaneStyle
    get() = Panes.HeaderButton.copy(
        tint = Ownify.paneBody(0.62f),
        shadows = listOf(
            BoxShadow(y = 10.dp, blur = 26.dp, color = Ownify.shade(0.30f)),
            BoxShadow(y = 1.dp, blur = 0.dp, color = Ownify.glint(0.16f), inset = true)
        )
    )

/**
 * `.ai-top`: the grabber; a round close on the left; in the middle the
 * island — the Ownify logo, OwnifyAI and how many conversations there are —
 * which opens Gesprekken out of itself. While OwnifyAI thinks it says so and
 * the logo glows.
 */
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
            .padding(top = maxOf(Ownify.Space2, insets.calculateTopPadding()), bottom = Ownify.Space3),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Box(
            Modifier
                .padding(top = Ownify.Space2)
                .size(38.dp, 4.dp)
                .background(Ownify.ink(0.20f), RoundedCornerShape(50))
        )
        Box(Modifier.width(screen.shell).padding(top = Ownify.Space2), contentAlignment = Alignment.Center) {
            RoundGlass(OwnifyIcons.chevronDown, ai.closeAria, Modifier.align(Alignment.CenterStart), onClick = shell::closeAi)
            Island(ai, enabled = view == AiView.Chat, Modifier.widthIn(max = screen.shell - 100.dp)) {
                if (OwnifyAssistant.historyOpen) OwnifyAssistant.historyOpen = false
                else OwnifyAssistant.openHistory(context, ai)
            }
        }
    }
}

/** `.ai-round`: a round button of the island's glass. */
@Composable
private fun RoundGlass(icon: ImageVector, label: String, modifier: Modifier = Modifier, size: Dp = 42.dp, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        Pane(
            modifier
                .press(interaction)
                .size(size)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics { contentDescription = label },
            style = IslandPane,
            shape = CircleShape
        ) {
            JIcon(icon, size = 20.dp, color = Ownify.TextPrimary)
        }
    }
}

/** `.ai-island`. */
@Composable
private fun Island(ai: AiCopy, enabled: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val a = OwnifyAssistant
    val thinking = a.sending != null
    val count = a.conversations.size
    val open = a.historyOpen
    val still = LocalReducedMotion.current || LocalStillMotion.current
    val turn by animateFloatAsState(if (open) 180f else 0f, if (still) snap() else tween(420, easing = Ownify.EaseOutStrong), label = "island-chevron")
    val size = if (still) snap<IntSize>() else tween<IntSize>(420, easing = Ownify.EaseOutStrong)

    InButton {
        val interaction = remember { MutableInteractionSource() }
        Pane(
            modifier
                .press(interaction, enabled = enabled)
                .height(44.dp)
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) {
                    contentDescription = trf("%s — je gesprekken", ai.title) + if (count > 0) ", $count" else ""
                },
            style = IslandPane,
            shape = RoundedCornerShape(50)
        ) {
            Row(
                Modifier
                    .animateContentSize(if (still) snap() else tween(420, easing = Ownify.EaseOutStrong))
                    .padding(start = 6.dp, end = if (thinking || !enabled) 18.dp else 16.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                AiLogo(32.dp, 30.dp, halo = thinking)
                T(
                    ai.title,
                    OwnifyType.style(Ownify.FsLabel, FontWeight.SemiBold, tracking = (-0.01).em, lineHeight = 1.em),
                    Modifier.padding(start = 9.dp),
                    maxLines = 1
                )
                AnimatedVisibility(
                    thinking,
                    enter = fadeIn(tween(300)) + expandHorizontally(size, expandFrom = Alignment.Start),
                    exit = fadeOut(tween(200)) + shrinkHorizontally(size, shrinkTowards = Alignment.Start)
                ) {
                    T(
                        tr("denkt na…"),
                        OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextSecondary, lineHeight = 1.em),
                        Modifier.padding(start = 10.dp).clearAndSetSemantics { },
                        maxLines = 1
                    )
                }
                if (!thinking && enabled) {
                    if (count > 0) {
                        T(
                            count.toString(),
                            OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextMuted, tabular = true, lineHeight = 1.em),
                            Modifier.padding(start = 9.dp),
                            maxLines = 1
                        )
                    }
                    Box(Modifier.padding(start = 9.dp).graphicsLayer { rotationZ = turn }) {
                        JIcon(OwnifyIcons.chevronDown, size = 14.dp, color = Ownify.TextMuted)
                    }
                }
            }
        }
    }
}

/**
 * `.ai-logo`: the official logo (docs/BRANDING.md), never redrawn — with a
 * soft green halo behind it that breathes while OwnifyAI thinks; [off]
 * greyed once OwnifyAI is turned off.
 */
@Composable
private fun AiLogo(box: Dp, image: Dp, halo: Boolean = false, off: Boolean = false) {
    val shown by animateFloatAsState(if (halo) 1f else 0f, tween(400, easing = Ownify.EaseOut), label = "halo")
    val pulse = if (halo) loopValue(0f, 1f, infiniteRepeatable(tween(800, easing = EaseInOutCss), RepeatMode.Reverse), still = 0.5f) else 0f
    val grey = remember { ColorFilter.colorMatrix(ColorMatrix().apply { setToSaturation(0f) }) }

    Box(Modifier.size(box), contentAlignment = Alignment.Center) {
        if (shown > 0f) {
            Canvas(Modifier.requiredSize(box + 8.dp)) {
                val scale = 0.9f + 0.22f * pulse
                val alpha = (0.25f + 0.65f * pulse) * shown
                drawCircle(
                    Brush.radialGradient(
                        listOf(Ownify.Health.copy(alpha = 0.45f * alpha), Ownify.Health.copy(alpha = 0f)),
                        center = center,
                        radius = this.size.minDimension / 2f * scale
                    ),
                    radius = this.size.minDimension / 2f * scale
                )
            }
        }
        Image(
            painterResource(R.drawable.ownify_logo),
            contentDescription = null,
            modifier = Modifier.size(image).alpha(if (off) 0.5f else 1f),
            colorFilter = if (off) grey else null
        )
    }
}

/**
 * `.ai-sky`: green light from above and a dawn at the foot — green in the
 * middle, blue to the left, violet to the right. Wide ellipses past the
 * edges, falling off along an eased curve, so neither reads as a spot or
 * ends in a line. The dawn brightens and breathes faster while OwnifyAI
 * thinks; White Mode keeps the same light, softer.
 */
@Composable
private fun AiSky(thinking: Boolean, modifier: Modifier) {
    val glowK = if (Ownify.light) 0.62f else 1f
    val dawnK = if (Ownify.light) 0.7f else 1f
    val sky = loopValue(0f, 1f, infiniteRepeatable(tween(18_000, easing = EaseInOutCss), RepeatMode.Reverse), still = 1f)
    val drift = loopValue(-1f, 1f, infiniteRepeatable(tween(26_000, easing = EaseInOutCss), RepeatMode.Reverse), still = 0f)
    val dawn = loopValue(0f, 1f, infiniteRepeatable(tween(20_000, easing = EaseInOutCss), RepeatMode.Reverse), still = 0f)
    val breath = loopValue(0f, 1f, infiniteRepeatable(tween(2_400, easing = EaseInOutCss), RepeatMode.Reverse), still = 0f)
    val think by animateFloatAsState(if (thinking) 1f else 0f, tween(700, easing = Ownify.EaseOut), label = "dawn")

    val green = Ownify.Health
    val skyStops = floatArrayOf(0.40f, 0.368f, 0.32f, 0.26f, 0.20f, 0.144f, 0.096f, 0.056f, 0.028f, 0.01f, 0f)
    val dawnStops = floatArrayOf(0.552f, 0.504f, 0.444f, 0.36f, 0.276f, 0.198f, 0.132f, 0.077f, 0.038f, 0.014f, 0f)

    fun eased(color: Color, alphas: FloatArray, k: Float): Array<Stop> =
        Array(alphas.size) { i -> Stop(i / (alphas.size - 1f), color.copy(alpha = (alphas[i] * k).coerceIn(0f, 1f))) }

    Canvas(modifier) {
        val w = size.width
        val h = size.height

        // The light from above: inset -10% -30% auto, 78% tall.
        val skyBox = Size(1.6f * w, 0.78f * h)
        val skyScale = 1f + 0.04f * sky
        withTransform({
            translate(-0.3f * w, -0.1f * h + (-0.02f + 0.02f * sky) * skyBox.height)
            scale(skyScale, skyScale, pivot = Offset(skyBox.width / 2f, skyBox.height / 2f))
        }) {
            cssRadialGradient(0.62f, 0.78f, 0.5f, 0f, *eased(green, skyStops, glowK * (0.82f + 0.18f * sky)), box = skyBox)
        }

        // A lighter green drifting across it: inset -6% -40% auto 10%, 50% tall.
        val glintBox = Size(1.3f * w, 0.5f * h)
        translate(0.1f * w + drift * 0.06f * glintBox.width, -0.06f * h) {
            cssRadialGradient(
                0.5f, 0.7f, 0.6f, 0f,
                Stop(0f, AiMint.copy(alpha = 0.14f * glowK)),
                Stop(0.35f, AiMint.copy(alpha = 0.08f * glowK)),
                Stop(0.65f, AiMint.copy(alpha = 0.03f * glowK)),
                Stop(1f, AiMint.copy(alpha = 0f)),
                box = glintBox
            )
        }

        // The dawn: inset auto -35% -2%, 50% tall, from its foot.
        val dawnBox = Size(1.7f * w, 0.5f * h)
        val sx = (1f + 0.05f * dawn) * (1f - think) + (1.03f + 0.05f * breath) * think
        val sy = (1f + 0.08f * dawn) * (1f - think) + (1.06f + 0.10f * breath) * think
        val k = dawnK * (0.9f + 0.1f * think)
        withTransform({
            translate(-0.35f * w, 1.02f * h - dawnBox.height)
            scale(sx, sy, pivot = Offset(dawnBox.width / 2f, dawnBox.height))
        }) {
            cssRadialGradient(0.6f, 0.88f, 0.5f, 1f, *eased(green, dawnStops, k), box = dawnBox)
            cssRadialGradient(
                0.34f, 0.62f, 0.78f, 1f,
                Stop(0f, AiViolet.copy(alpha = 0.32f * k)), Stop(0.3f, AiViolet.copy(alpha = 0.19f * k)),
                Stop(0.6f, AiViolet.copy(alpha = 0.06f * k)), Stop(1f, AiViolet.copy(alpha = 0f)),
                box = dawnBox
            )
            cssRadialGradient(
                0.34f, 0.62f, 0.24f, 1f,
                Stop(0f, AiBlue.copy(alpha = 0.36f * k)), Stop(0.3f, AiBlue.copy(alpha = 0.22f * k)),
                Stop(0.6f, AiBlue.copy(alpha = 0.08f * k)), Stop(1f, AiBlue.copy(alpha = 0f)),
                box = dawnBox
            )
        }
    }
}

/* ------------------------------------------------------------- consent */

private val PrimaryLook: BtnLook
    get() = BtnLook(border = Ownify.HealthStrong, fill = Ownify.HealthEdge)

@Composable
private fun ConsentScreen(ai: AiCopy, modifier: Modifier) {
    val screen = LocalScreen.current
    val context = LocalContext.current
    val consent = ai.consent
    val busy = OwnifyAssistant.consentBusy

    Column(modifier.fillMaxWidth().verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally) {
        Column(Modifier.width(screen.shell).padding(top = Ownify.Space2, bottom = Ownify.Space5), horizontalAlignment = Alignment.CenterHorizontally) {
            AiLogo(56.dp, 56.dp)
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
            T(consent.footer, JStyle.Footnote, Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
        }
    }
}

@Composable
private fun DeclinedScreen(ai: AiCopy, modifier: Modifier) {
    val screen = LocalScreen.current

    Column(modifier.fillMaxWidth().verticalScroll(rememberScrollState()), horizontalAlignment = Alignment.CenterHorizontally) {
        Column(Modifier.width(screen.shell).padding(top = Ownify.Space2, bottom = Ownify.Space5), horizontalAlignment = Alignment.CenterHorizontally) {
            AiLogo(56.dp, 56.dp, off = true)
            T(ai.declined.title, JStyle.Section, Modifier.padding(top = Ownify.Space4).semantics { heading() }, align = TextAlign.Center)
            T(ai.declined.body, JStyle.Lede, Modifier.padding(top = Ownify.Space2), align = TextAlign.Center)
            Btn(ai.declined.review, onClick = { OwnifyAssistant.reviewing = true }, Modifier.padding(top = Ownify.Space5).widthIn(min = 220.dp))
            T(ai.consent.footer, JStyle.Footnote, Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
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
    val queued = a.queued
    val list = rememberLazyListState()
    val count = messages.size + (if (sending != null) 2 else 0) + (if (queued != null) 1 else 0)

    // To the very end, as the website scrolls to it — the newest message's
    // bottom in view, however tall it is (the list stops at its end).
    LaunchedEffect(count, messages.lastOrNull()?.action?.state, a.notice) {
        if (count > 0) list.animateScrollToItem(count - 1, scrollOffset = 100_000)
    }

    if (messages.isEmpty() && sending == null && queued == null) {
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
        verticalArrangement = Arrangement.spacedBy(Ownify.Space3)
    ) {
        items(messages, key = { "m" + it.id }) { message ->
            Box(Modifier.width(screen.shell)) { MessageView(ai, message) }
        }
        // `.ai-msg--waiting`: there, but not sent yet — the capsule counts down to it.
        if (queued != null) {
            item(key = "queued") { Box(Modifier.width(screen.shell).alpha(0.55f)) { UserBubble(queued) } }
        }
        if (sending != null) {
            item(key = "sending") { Box(Modifier.width(screen.shell)) { UserBubble(sending) } }
            item(key = "thinking") { Box(Modifier.width(screen.shell)) { AssistantPane(PaddingValues(horizontal = 15.dp, vertical = 13.dp)) { Thinking(ai) } } }
        }
    }
}

/** `.ai-empty`: the logo, "Wat wil je weten?", what OwnifyAI is for, three questions to start with. */
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
            Box(Modifier.padding(bottom = Ownify.Space2)) { AiLogo(64.dp, 64.dp) }
            T(
                ai.empty.title,
                OwnifyType.style(screen.fsBrand, FontWeight.SemiBold, tracking = (-0.03).em, lineHeight = 1.25.em),
                Modifier.padding(top = Ownify.Space3).semantics { heading() },
                align = TextAlign.Center
            )
            T(ai.empty.body, OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary), Modifier.padding(top = Ownify.Space2), align = TextAlign.Center)
            if (!hasData && ai.empty.noData.isNotEmpty()) {
                T(ai.empty.noData, JStyle.Footnote, Modifier.padding(top = Ownify.Space3), align = TextAlign.Center)
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

/** `.ai-msg--user`: your words on the right, in the green, white on it. */
@Composable
private fun UserBubble(text: String) {
    val screen = LocalScreen.current
    val shape = RoundedCornerShape(20.dp, 20.dp, 6.dp, 20.dp)
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
        Box(
            Modifier
                .widthIn(max = screen.shell * 0.82f)
                .clip(shape)
                .background(AiUser)
                .padding(start = 14.dp, end = 14.dp, top = 9.dp, bottom = 10.dp)
        ) {
            T(text, OwnifyType.style(Ownify.FsBody, color = Color.White, lineHeight = 1.42.em))
        }
    }
}

/** `.ai-msg--assistant`: OwnifyAI in grey glass, on the left. */
@Composable
private fun AssistantPane(padding: PaddingValues = PaddingValues(start = 14.dp, end = 14.dp, top = 10.dp, bottom = 11.dp), content: @Composable () -> Unit) {
    val screen = LocalScreen.current
    val shape = RoundedCornerShape(20.dp, 20.dp, 20.dp, 6.dp)
    // White glass: faint on the dark ground, near-white on the light one (ai.css --ai-bubble).
    val body = Ownify.glint(if (Ownify.light) 0.26f else 0.085f)
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Start) {
        Column(
            Modifier
                .widthIn(max = screen.shell * 0.88f)
                .clip(shape)
                .background(Brush.verticalGradient(listOf(Ownify.glint(0.05f), Ownify.glint(0.015f))))
                .background(body)
                .border(1.dp, Ownify.GlassHairline, shape)
                .padding(padding),
            verticalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            content()
        }
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
            .background(if (done) Ownify.GlassSoft else Ownify.HealthWash)
            .border(1.dp, if (done) Ownify.GlassBorderSoft else Ownify.HealthEdge, shape)
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
            .height(10.dp)
            .semantics {
                contentDescription = ai.thinking
                liveRegion = LiveRegionMode.Polite
            },
        horizontalArrangement = Arrangement.spacedBy(5.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        repeat(3) { i ->
            // ai.css @keyframes ai-typing: each dot lifts 3 dp and brightens, in turn.
            val t = if (still) 0f else ((phase - i * 0.375f + 3f) % 3f) / 3f
            val up = if (t < 0.35f) t / 0.35f else if (t < 0.7f) 1f - (t - 0.35f) / 0.35f else 0f
            Box(
                Modifier
                    .graphicsLayer { translationY = -3.dp.toPx() * up }
                    .size(7.dp)
                    .alpha(if (still) 0.6f else 0.45f + 0.55f * up)
                    .background(Ownify.ink(0.45f), CircleShape)
            )
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

        val shape = RoundedCornerShape(28.dp)
        // `.ai-composer__form:focus-within`: the strong green edge and the focus halo.
        var focused by remember { mutableStateOf(false) }
        Row(
            Modifier
                .fillMaxWidth()
                .drawBehind {
                    if (focused) {
                        val ring = Ownify.FocusHaloWidth.toPx()
                        val r = 28.dp.toPx() + ring / 2f
                        drawRoundRect(
                            color = Ownify.FocusHalo,
                            topLeft = Offset(-ring / 2f, -ring / 2f),
                            size = Size(size.width + ring, size.height + ring),
                            cornerRadius = CornerRadius(r, r),
                            style = Stroke(ring)
                        )
                    }
                }
                .clip(shape)
                .background(Ownify.paneBody(0.62f))
                .background(Brush.verticalGradient(listOf(Ownify.glint(0.10f), Ownify.glint(0.04f))))
                .border(1.dp, if (focused) Ownify.HealthStrong else Ownify.GlassBorder, shape)
                .padding(start = Ownify.Space4, end = 6.dp, top = 6.dp, bottom = 6.dp),
            verticalAlignment = Alignment.Bottom
        ) {
            val style = OwnifyType.style(Ownify.FsInput, color = Ownify.TextPrimary, lineHeight = 1.4.em, tracking = 0.sp)
            BasicTextField(
                value = a.draft,
                onValueChange = { a.draft = it.take(2000) },
                modifier = Modifier
                    .weight(1f)
                    .heightIn(min = 40.dp)
                    .padding(vertical = 9.dp)
                    .onFocusChanged { focused = it.isFocused }
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
            SendButton(ai.send, enabled = !out && !sending && a.queued == null && a.draft.isNotBlank()) {
                a.ask(context, ai, a.draft)
            }
        }

        val left = ai.remainingText(usage)
        if (left.isNotEmpty()) {
            T(left, JStyle.Tiny, Modifier.fillMaxWidth().padding(top = Ownify.Space2), align = TextAlign.Center)
        }
    }
}

/**
 * `.ai-wait`: a question sent while the minute is full waits here — the
 * sync capsule's glass (PullSyncIndicator), under the top. The seconds roll
 * down inside a ring that drains; Annuleren puts the question back in the
 * field; at 0 the ring turns, it says Versturen… and the question goes.
 * Read out once, not every second.
 */
@OptIn(ExperimentalAnimationApi::class)
@Composable
private fun WaitCapsule(modifier: Modifier) {
    val a = OwnifyAssistant
    val showing = a.queued != null || a.queuedSending
    val sendingNow = a.queuedSending
    val still = LocalReducedMotion.current || LocalStillMotion.current
    var left by remember { mutableIntStateOf(a.waitLeft()) }
    LaunchedEffect(showing, a.waitUntil) {
        while (showing) {
            left = a.waitLeft()
            delay(200)
        }
    }
    val total = a.waitTotal.coerceAtLeast(1)
    val drained by animateFloatAsState(
        ((left - 1).coerceAtLeast(0)).toFloat() / total,
        if (still) snap() else tween(1000, easing = LinearEasing),
        label = "wait-ring"
    )
    val spoken = remember(a.queued) { trf("Het is even druk bij OwnifyAI. Je vraag wordt over %d seconden verstuurd.", a.waitLeft()) }

    AnimatedVisibility(
        showing,
        modifier,
        enter = if (still) fadeIn(tween(200)) else fadeIn(tween(240)) + scaleIn(tween(420, easing = Ownify.EaseOutStrong), initialScale = 0.6f, transformOrigin = TransformOrigin(0.5f, 0f)),
        exit = if (still) fadeOut(tween(200)) else fadeOut(tween(240)) + scaleOut(tween(240, easing = Ownify.EaseOutStrong), targetScale = 0.85f, transformOrigin = TransformOrigin(0.5f, 0f))
    ) {
        Pane(
            Modifier
                .height(40.dp)
                .semantics(mergeDescendants = true) {
                    contentDescription = if (sendingNow) tr("Versturen…") else spoken
                    liveRegion = LiveRegionMode.Polite
                },
            style = Panes.HeaderButton,
            shape = RoundedCornerShape(50),
            contentAlignment = Alignment.CenterStart
        ) {
            Row(Modifier.fillMaxHeight().animateContentSize(tween(320, easing = Ownify.EaseOutStrong)), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(38.dp), contentAlignment = Alignment.Center) {
                    if (sendingNow) {
                        Spinner()
                    } else {
                        val track = Ownify.ink(0.12f)
                        val arc = Ownify.Health
                        Canvas(Modifier.size(28.dp)) {
                            val inset = 1.1.dp.toPx() + 1.dp.toPx()
                            val box = Size(size.width - 2 * inset, size.height - 2 * inset)
                            val at = Offset(inset, inset)
                            drawArc(track, 0f, 360f, false, at, box, style = Stroke(2.2.dp.toPx()))
                            if (drained > 0f) drawArc(arc, -90f, 360f * drained, false, at, box, style = Stroke(2.2.dp.toPx(), cap = StrokeCap.Round))
                        }
                        AnimatedContent(
                            left,
                            transitionSpec = {
                                if (still) (fadeIn(snap()) togetherWith fadeOut(snap()))
                                else (slideInVertically(tween(280, easing = Ownify.EaseOutStrong)) { it * 7 / 10 } + fadeIn(tween(280)))
                                    .togetherWith(slideOutVertically(tween(280, easing = Ownify.EaseOutStrong)) { -it * 7 / 10 } + fadeOut(tween(280)))
                            },
                            label = "wait-seconds"
                        ) { n ->
                            T(n.coerceAtLeast(0).toString(), OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextPrimary, tabular = true, lineHeight = 1.em), maxLines = 1)
                        }
                    }
                }
                T(
                    if (sendingNow) tr("Versturen…") else tr("Even druk — je vraag wacht"),
                    OwnifyType.style(Ownify.FsSmall, FontWeight.Medium, Ownify.TextSecondary, lineHeight = 1.em),
                    Modifier.padding(end = if (sendingNow) Ownify.Space4 else Ownify.Space3),
                    maxLines = 1
                )
                if (!sendingNow) {
                    Box(Modifier.width(1.dp).fillMaxHeight().background(Ownify.GlassHairline))
                    val interaction = remember { MutableInteractionSource() }
                    Box(
                        Modifier
                            .fillMaxHeight()
                            .clickable(interaction, indication = null, role = Role.Button) { a.cancelQueued() }
                            .semantics { contentDescription = tr("Annuleren") }
                            .padding(start = Ownify.Space3, end = Ownify.Space3 + 3.dp),
                        contentAlignment = Alignment.Center
                    ) {
                        T(tr("Annuleren"), OwnifyType.style(Ownify.FsSmall, FontWeight.SemiBold, Ownify.TextPrimary, lineHeight = 1.em), maxLines = 1)
                    }
                }
            }
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
            .background(Ownify.AttentionWash)
            .border(1.dp, Ownify.AttentionEdge, shape)
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

/** Lower case, without accents: "Eiwit" finds "eiwit", "café" finds "cafe". */
private fun plain(text: String): String =
    java.text.Normalizer.normalize(text.lowercase(), java.text.Normalizer.Form.NFD).replace(Regex("\\p{Mn}+"), "")

/**
 * `.ai-history`: Gesprekken, growing out of the island — scaled from the top
 * centre, and folding back the same way, a little quicker. The sheet dims
 * behind it; a tap there, the chevron or Back closes it. Search, Nieuw
 * gesprek in grey glass, and the conversations by when they were last used.
 */
@Composable
private fun HistoryLayer(ai: AiCopy) {
    val a = OwnifyAssistant
    val open = a.historyOpen
    val still = LocalReducedMotion.current || LocalStillMotion.current
    val progress = remember { Animatable(if (open) 1f else 0f) }
    LaunchedEffect(open, still) {
        progress.animateTo(
            if (open) 1f else 0f,
            when {
                still -> tween(220)
                open -> tween(480, easing = Ownify.EaseOutStrong)
                else -> tween(320, easing = Ownify.EaseOutStrong)
            }
        )
    }
    if (!open && progress.value == 0f) return

    BackHandler(enabled = open) { a.historyOpen = false }

    val screen = LocalScreen.current
    val context = LocalContext.current
    val insets = WindowInsets.safeDrawing.asPaddingValues()
    var query by remember { mutableStateOf("") }
    LaunchedEffect(open) { if (open) query = "" }
    val p = progress.value
    val dismiss = remember { MutableInteractionSource() }

    Box(Modifier.fillMaxSize()) {
        Box(
            Modifier
                .fillMaxSize()
                .graphicsLayer { alpha = (p * 1.3f).coerceAtMost(1f) }
                .background(Ownify.scrim(0.48f))
                .clickable(dismiss, indication = null, enabled = open) { a.historyOpen = false }
        )

        val shape = RoundedCornerShape(28.dp)
        Column(
            Modifier
                .align(Alignment.TopCenter)
                .padding(top = maxOf(Ownify.Space2, insets.calculateTopPadding()) + 10.dp, start = 12.dp, end = 12.dp)
                .widthIn(max = 640.dp)
                .fillMaxWidth()
                .heightIn(max = minOf(screen.height * 0.78f, 640.dp))
                .graphicsLayer {
                    transformOrigin = TransformOrigin(0.5f, 0f)
                    if (!still) {
                        scaleX = 0.42f + 0.58f * p
                        scaleY = 0.08f + 0.92f * p
                        translationY = -6.dp.toPx() * (1f - p)
                    }
                    alpha = if (still) p else (p / 0.55f).coerceAtMost(1f)
                }
                // ai.css: 0 22px 50px shade .40 — a soft shadow under the card.
                .shadow(28.dp, shape, ambientColor = Ownify.shade(0.40f), spotColor = Ownify.shade(0.40f))
                .clip(shape)
                // Solid: no blur behind it on Android, so the conversation must not show through.
                .background(Ownify.ground(1f))
                .background(Brush.verticalGradient(listOf(Ownify.glint(0.10f), Ownify.glint(0.03f))))
                .border(1.dp, Ownify.GlassBorder, shape)
                .clickable(remember { MutableInteractionSource() }, indication = null) { }
                .padding(start = Ownify.Space4, end = Ownify.Space4, top = Ownify.Space4, bottom = Ownify.Space2)
        ) {
            Row(Modifier.fillMaxWidth().padding(start = 2.dp, end = 2.dp, top = 2.dp, bottom = Ownify.Space3), verticalAlignment = Alignment.CenterVertically) {
                T(
                    ai.history,
                    OwnifyType.style(Ownify.FsSection, FontWeight.SemiBold, tracking = (-0.02).em, lineHeight = 1.2.em),
                    Modifier.weight(1f).semantics { heading() }
                )
                RoundGlass(OwnifyIcons.chevron, tr("Sluiten"), size = 38.dp) { a.historyOpen = false }
            }

            if (a.conversations.isNotEmpty()) SearchField(query) { query = it }

            NewChatButton(ai.newChat) { a.startNew(ai) }

            val shown = remember(a.conversations, query) {
                val q = plain(query.trim())
                a.conversations.filter { q.isEmpty() || plain(it.title).contains(q) }
            }
            when {
                a.conversations.isEmpty() -> EmptyLine(ai.historyEmpty)
                shown.isEmpty() -> EmptyLine(tr("Geen gesprekken gevonden."))
                else -> {
                    val order = listOf("today", "week", "earlier")
                    val groups = shown.groupBy { it.group }.toList().sortedBy { (key, _) -> order.indexOf(key).let { if (it < 0) 3 else it } }
                    LazyColumn(Modifier.padding(top = Ownify.Space2).fillMaxWidth()) {
                        groups.forEach { (key, rows) ->
                            item(key = "g-$key") {
                                T(
                                    rows.first().groupLabel.uppercase(),
                                    OwnifyType.style(Ownify.FsTiny, FontWeight.SemiBold, Ownify.TextMuted, tracking = 0.08.em),
                                    Modifier.padding(start = 4.dp, end = 4.dp, top = Ownify.Space3, bottom = 6.dp).semantics { heading() }
                                )
                            }
                            items(rows, key = { "c" + it.id }) { conversation ->
                                HistoryRow(ai, conversation, current = a.conversation?.id == conversation.id) {
                                    a.open(context, ai, conversation.id)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/** `.ai-history__search`: Zoek in je gesprekken. */
@Composable
private fun SearchField(value: String, onChange: (String) -> Unit) {
    val shape = RoundedCornerShape(12.dp)
    var focused by remember { mutableStateOf(false) }
    val style = OwnifyType.style(Ownify.FsInput, color = Ownify.TextPrimary, lineHeight = 1.2.em, tracking = 0.sp)
    val label = tr("Zoek in je gesprekken")
    Row(
        Modifier
            .padding(bottom = Ownify.Space2)
            .fillMaxWidth()
            .height(40.dp)
            .clip(shape)
            .background(Ownify.Glass)
            .border(1.dp, if (focused) Ownify.HealthStrong else Ownify.GlassHairline, shape)
            .padding(horizontal = Ownify.Space3),
        verticalAlignment = Alignment.CenterVertically
    ) {
        JIcon(OwnifyIcons.search, size = 16.dp, color = Ownify.TextMuted)
        BasicTextField(
            value = value,
            onValueChange = { onChange(it.take(100)) },
            modifier = Modifier
                .padding(start = Ownify.Space2)
                .weight(1f)
                .onFocusChanged { focused = it.isFocused }
                .semantics { contentDescription = label },
            textStyle = style,
            singleLine = true,
            cursorBrush = SolidColor(Ownify.TextPrimary),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            decorationBox = { inner ->
                Box(contentAlignment = Alignment.CenterStart) {
                    if (value.isEmpty()) T(label, style.copy(color = Ownify.TextMuted), maxLines = 1)
                    inner()
                }
            }
        )
    }
}

/** `.ai-history__new`: Nieuw gesprek in grey glass — the action, without the green. */
@Composable
private fun NewChatButton(label: String, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val shape = RoundedCornerShape(16.dp)
        Row(
            Modifier
                .press(interaction)
                .fillMaxWidth()
                .height(46.dp)
                .clip(shape)
                .background(Ownify.fill(0.08f))
                .background(Brush.verticalGradient(listOf(Ownify.glint(0.10f), Ownify.glint(0.04f))))
                .border(1.dp, Ownify.GlassBorder, shape)
                .clickable(interaction, indication = null, role = Role.Button, onClick = blurring(onClick))
                .semantics(mergeDescendants = true) { contentDescription = label },
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.CenterVertically
        ) {
            JIcon(OwnifyIcons.plus, size = 18.dp, color = Ownify.TextPrimary)
            T(label, OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold), Modifier.padding(start = Ownify.Space2), maxLines = 1)
        }
    }
}

@Composable
private fun EmptyLine(text: String) {
    T(
        text,
        OwnifyType.style(Ownify.FsSmall, color = Ownify.TextMuted),
        Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 18.dp),
        align = TextAlign.Center
    )
}

/** `.ai-history__item`: the title, when · how many questions, the one you are in marked; delete in two presses. */
@Composable
private fun HistoryRow(ai: AiCopy, conversation: AiConversation, current: Boolean, onOpen: () -> Unit) {
    val context = LocalContext.current
    var armed by remember(conversation.id) { mutableStateOf(false) }
    val shape = RoundedCornerShape(14.dp)
    Row(
        Modifier
            .padding(top = 2.dp)
            .fillMaxWidth()
            .heightIn(min = 56.dp)
            .clip(shape)
            .then(if (current) Modifier.background(Ownify.Glass).border(1.dp, Ownify.GlassHairline, shape) else Modifier),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(
            Modifier
                .weight(1f)
                .clickable(role = Role.Button, onClick = onOpen)
                .padding(start = 12.dp, end = 4.dp, top = 9.dp, bottom = 9.dp)
        ) {
            T(conversation.title, OwnifyType.style(Ownify.FsBody, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
            T(
                conversation.meta ?: conversation.label,
                OwnifyType.style(Ownify.FsFootnote, color = Ownify.TextMuted),
                Modifier.padding(top = 2.dp),
                maxLines = 1,
                ellipsis = true
            )
        }
        if (current) Box(Modifier.size(8.dp).background(Ownify.Health, CircleShape))
        if (armed) {
            Box(Modifier.padding(start = 4.dp, end = 4.dp)) {
                Btn(ai.deleteChat + "?", onClick = { OwnifyAssistant.delete(context, ai, conversation.id) }, look = BtnLook.Final)
            }
        } else {
            Box(
                Modifier
                    .padding(start = 4.dp, end = 4.dp)
                    .size(40.dp)
                    .clip(CircleShape)
                    .clickable(role = Role.Button) { armed = true }
                    .semantics { contentDescription = ai.deleteChat + ": " + conversation.title },
                contentAlignment = Alignment.Center
            ) {
                JIcon(OwnifyIcons.trash, size = 18.dp, color = Ownify.TextFaint)
            }
        }
    }
}
