package com.healthapp.android.ui.app

import androidx.activity.compose.BackHandler
import androidx.activity.compose.PredictiveBackHandler
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalGraphicsContext
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.healthapp.android.data.AppData
import com.healthapp.android.ui.design.BoxShadow
import com.healthapp.android.ui.design.Ground
import com.healthapp.android.ui.design.GroundPlacement
import com.healthapp.android.ui.design.LocalBackdrop
import com.healthapp.android.ui.design.LocalGround
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.LocalVisibility
import com.healthapp.android.ui.design.Visibility
import com.healthapp.android.ui.design.drawBoxShadows
import com.healthapp.android.ui.design.ground
import com.healthapp.android.ui.design.recordBackdrop
import com.healthapp.android.ui.design.rememberBackdrop
import com.healthapp.android.ui.theme.Accent
import com.healthapp.android.ui.theme.Jolu
import kotlinx.coroutines.CancellationException

/** The shell, for the screens inside it: where to go, what to open. */
val LocalShell = staticCompositionLocalOf<ShellState> { error("No shell") }

/** Horizontal drags a screen reads itself (the goal chart). */
val LocalOwnedAreas = staticCompositionLocalOf { OwnedAreas() }

/** What the shell draws in each of its places — the pages, the details, the sheet, the panels. */
class ShellScreens(
    val page: @Composable (id: String, data: AppData, scroll: ScrollState) -> Unit,
    val detail: @Composable (detail: Detail, data: AppData, scroll: ScrollState) -> Unit,
    val assistant: @Composable (data: AppData) -> Unit,
    val overlay: @Composable (overlay: Overlay, data: AppData) -> Unit,
    /** The page whose header stays clear because its head never scrolls (Community). */
    val fixedHead: Set<String> = setOf("community")
)

/**
 * The signed-in app (index.php): the deck — the page rail with its shared
 * header, and the detail layer above it — the dock, the assistant sheet, and
 * the panels in front of everything.
 */
@Composable
fun AppShell(data: AppData, screens: ShellScreens, onShell: ((ShellState) -> Unit)? = null) {
    val scope = rememberCoroutineScope()
    val pageIds = remember(data.navigation) { data.navigation.map { it.id } }
    val start = remember(data.navigation) { data.navigation.firstOrNull { it.active }?.id ?: "overview" }
    val shell = remember(pageIds) { ShellState(pageIds, start, scope) }

    // For tests and screenshots: the shell, to move it as a finger would.
    LaunchedEffect(shell) { onShell?.invoke(shell) }
    val owned = remember { OwnedAreas() }
    val density = LocalDensity.current
    val screen = LocalScreen.current

    val (railBackdrop, railLayer) = rememberBackdrop()
    val (deckBackdrop, deckLayer) = rememberBackdrop()
    val (appBackdrop, appLayer) = rememberBackdrop()
    val appGround = remember { GroundPlacement(Ground.App) }

    // A goal or detail that no longer exists (deleted, signed in as someone else) closes.
    LaunchedEffect(data, shell.detail) {
        val detail = shell.detail ?: return@LaunchedEffect
        val exists = when (detail) {
            is Detail.GoalPage -> data.goals.goal(detail.id) != null
            is Detail.HealthArea -> data.health.area(detail.id) != null
            is Detail.SettingsPage -> data.settings.page(detail.id) != null
        }
        if (!exists) shell.closeDetail()
    }

    // System back: a panel, then the sheet. A detail page follows the back gesture.
    BackHandler(enabled = shell.overlayOpen || shell.aiOpen) { shell.back() }
    PredictiveBackHandler(enabled = !shell.overlayOpen && !shell.aiOpen && shell.detail != null && shell.detailShown) { progress ->
        try {
            progress.collect { event -> shell.backProgress(event.progress) }
            shell.closeDetail()
        } catch (e: CancellationException) {
            shell.backCancelled()
            throw e
        }
    }

    CompositionLocalProvider(LocalShell provides shell, LocalOwnedAreas provides owned) {
        Box(Modifier.fillMaxSize().background(Jolu.BgDeep)) {
            // Everything below the panels, recorded for their scrims and glass.
            Box(
                Modifier
                    .fillMaxSize()
                    .recordBackdrop(appBackdrop, appLayer)
                    .deckGestures({ shell.controllers }, owned) { density.density }
            ) {
                // The deck below the dock: what the tab bar looks through.
                Box(Modifier.fillMaxSize().recordBackdrop(deckBackdrop, deckLayer)) {
                    Box(Modifier.fillMaxSize().ground(appGround))

                    CompositionLocalProvider(LocalGround provides appGround) {
                        Rail(shell, data, screens, Modifier.recordBackdrop(railBackdrop, railLayer))
                    }

                    CompositionLocalProvider(LocalBackdrop provides railBackdrop, LocalGround provides appGround) {
                        val scroll = shell.scrolls[shell.currentPage]
                        val scrolled by remember(shell.currentPage) {
                            derivedStateOf { shell.currentPage !in screens.fixedHead && (scroll?.value ?: 0) > with(density) { 8.dp.roundToPx() } }
                        }
                        val covered = shell.aiOpen || (shell.detail != null && shell.detailShown)
                        SharedHeader(
                            appName = data.app.name,
                            devicesAria = data.header.devicesAria,
                            accountAria = data.header.accountAria,
                            avatar = data.auth.avatar,
                            requests = data.community.pending.size,
                            scrolled = scrolled,
                            onDevices = { shell.open(Overlay.Devices) },
                            onAccount = { shell.open(Overlay.Account()) },
                            modifier = if (covered) Modifier.clearAndSetSemantics { } else Modifier
                        )
                    }

                    DetailLayer(shell, data, screens)
                }

                CompositionLocalProvider(LocalBackdrop provides deckBackdrop) {
                    Dock(
                        items = data.navigation,
                        current = shell.currentPage,
                        openAria = data.ai.openAria,
                        onTab = shell::tab,
                        onOpenAssistant = shell::openAi,
                        onZones = { shell.dockZones = it },
                        modifier = Modifier
                            .align(Alignment.BottomCenter)
                            .then(if (shell.aiOpen) Modifier.clearAndSetSemantics { } else Modifier)
                    )
                }

                // .sheet-scrim: the page below stays present, just dimmed.
                if (shell.aiProgress > 0f) {
                    Box(
                        Modifier
                            .fillMaxSize()
                            .graphicsLayer { alpha = shell.aiProgress * 0.5f }
                            .background(Color.Black.copy(alpha = 0.42f))
                    )
                }

                Sheet(shell) { screens.assistant(data) }
            }

            // The panels, over everything, looking through all of it.
            CompositionLocalProvider(LocalBackdrop provides appBackdrop) {
                for (overlay in shell.overlays.toList()) {
                    screens.overlay(overlay, data)
                }
            }
        }
    }
}

/**
 * The rail: five pages side by side, moved as one. Each page keeps its own
 * scroller; the ones off screen, and every page under a detail or the sheet,
 * are out of reach.
 */
@Composable
private fun Rail(shell: ShellState, data: AppData, screens: ShellScreens, modifier: Modifier) {
    val width = LocalScreen.current.width
    val density = LocalDensity.current
    val covered = shell.aiOpen || (shell.detail != null && shell.detailShown)
    val visibility = remember { shell.pageIds.associateWith { Visibility() } }

    // Every page stays composed, as the website keeps every page in the
    // document: its scroll, its chosen tab and its half-typed input survive.
    Box(modifier.fillMaxSize()) {
        shell.pageIds.forEachIndexed { i, id ->
            val hidden = i != shell.index || covered
            Box(
                Modifier
                    .fillMaxSize()
                    .graphicsLayer {
                        val offset = i - shell.rail
                        translationX = offset * with(density) { width.toPx() }
                        // Nothing to draw more than a page away.
                        alpha = if (offset <= -1f || offset >= 1f) 0f else 1f
                    }
                    .then(if (hidden) Modifier.clearAndSetSemantics { } else Modifier)
            ) {
                CompositionLocalProvider(LocalVisibility provides visibility.getValue(id)) {
                    screens.page(id, data, shell.scrolls.getValue(id))
                }
            }
        }
    }
}

/** The detail layer: above the rail, below the dock; slides in from the right. */
@Composable
private fun DetailLayer(shell: ShellState, data: AppData, screens: ShellScreens) {
    val detail = shell.detail ?: return
    val width = LocalScreen.current.width
    val density = LocalDensity.current
    val shadows = LocalGraphicsContext.current.shadowContext
    val accent = when (detail) {
        is Detail.HealthArea -> Accent.of(data.health.area(detail.id)?.accent)
        is Detail.GoalPage -> Accent.of(data.goals.goal(detail.id)?.accent)
        is Detail.SettingsPage -> Accent.HEALTH
    }
    val ground = remember(detail.key, accent) { GroundPlacement(Ground.detail(accent)) }
    val progress = shell.detailProgress
    val visibility = remember(detail.key) { Visibility() }

    Box(
        Modifier
            .fillMaxSize()
            .graphicsLayer { translationX = (1f - progress) * with(density) { width.toPx() } }
            .drawWithContent {
                // Lifted off the rail while it is anywhere but parked.
                drawBoxShadows(
                    androidx.compose.ui.graphics.RectangleShape,
                    listOf(BoxShadow(x = (-18).dp, y = 0.dp, blur = 42.dp, color = Color.Black.copy(alpha = 0.34f))),
                    shadows
                )
                drawContent()
                drawRect(Jolu.white(0.07f), size = size.copy(width = 1.dp.toPx()))
            }
            .ground(ground)
            .then(if (shell.aiOpen || !shell.detailShown) Modifier.clearAndSetSemantics { } else Modifier)
    ) {
        CompositionLocalProvider(LocalGround provides ground, LocalVisibility provides visibility) {
            screens.detail(detail, data, shell.detailScroll)
        }
    }
}

/** The assistant sheet: over the rail and the dock, rounded at the top while it is out. */
@Composable
private fun Sheet(shell: ShellState, content: @Composable () -> Unit) {
    if (shell.aiProgress <= 0f && !shell.aiOpen) return
    val density = LocalDensity.current
    val height = LocalScreen.current.height
    val shadows = LocalGraphicsContext.current.shadowContext
    val shape = androidx.compose.foundation.shape.RoundedCornerShape(topStart = Jolu.RadiusCard, topEnd = Jolu.RadiusCard)
    val ground = remember { GroundPlacement(Ground.Assistant) }

    Box(
        Modifier
            .fillMaxSize()
            .graphicsLayer { translationY = (1f - shell.aiProgress) * with(density) { height.toPx() } }
            .drawWithContent {
                drawBoxShadows(shape, listOf(BoxShadow(y = (-18).dp, blur = 42.dp, color = Color.Black.copy(alpha = 0.36f))), shadows)
                drawContent()
            }
            .graphicsLayer {
                this.shape = shape
                clip = true
            }
            .ground(ground)
            .then(if (!shell.aiOpen) Modifier.clearAndSetSemantics { } else Modifier)
    ) {
        CompositionLocalProvider(LocalGround provides ground) { content() }
    }
}

/**
 * A page's scroller and column (`.screen__scroll` > `.app__main` >
 * `.shell.stack`): the header's room and 24 above, room for the dock and the
 * floating control below, the stack's 16 between blocks.
 */
@Composable
fun PageColumn(
    scroll: ScrollState,
    modifier: Modifier = Modifier,
    top: Dp = headerSpace(),
    mainTop: Dp = LocalScreen.current.mainTop,
    content: @Composable ColumnScope.() -> Unit
) {
    val screen = LocalScreen.current
    val bottom = WindowInsets.safeDrawing.asPaddingValues().calculateBottomPadding()
    Column(
        modifier
            .fillMaxSize()
            .verticalScroll(scroll)
            .padding(top = top + mainTop, bottom = dockClearance(bottom) + Jolu.Space6 + Jolu.Space6 + Jolu.Space5),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Column(Modifier.width(screen.shell), verticalArrangement = Arrangement.spacedBy(screen.stackGap), content = content)
    }
}

/**
 * A detail page (`article.detail` > `.screen__scroll`): its own header with
 * the back pill, sticky over its own column, which takes the tint and blur
 * once the column has moved 8 dp — the column recorded for that blur.
 */
@Composable
fun DetailColumn(
    scroll: ScrollState,
    back: String,
    backAria: String,
    content: @Composable ColumnScope.() -> Unit
) {
    val shell = LocalShell.current
    val density = LocalDensity.current
    val (backdrop, layer) = rememberBackdrop()
    val scrolled by remember(scroll) { derivedStateOf { scroll.value > with(density) { 8.dp.roundToPx() } } }

    Box(Modifier.fillMaxSize()) {
        PageColumn(scroll, Modifier.recordBackdrop(backdrop, layer), top = detailHeaderSpace(), content = content)
        CompositionLocalProvider(LocalBackdrop provides backdrop) {
            DetailHeader(back, backAria, scrolled, onBack = shell::closeDetail)
        }
    }
}

/** `--dock-clearance`: the tab bar, its gap and the safe bottom. */
fun dockClearance(safeBottom: Dp): Dp = Jolu.TabbarHeight + Jolu.DockGap + safeBottom

/** A box over a page, in the page's own layer (the scroll-to-top control). */
@Composable
fun PageOverlay(content: @Composable BoxScope.() -> Unit) = Box(Modifier.fillMaxSize(), content = content)
