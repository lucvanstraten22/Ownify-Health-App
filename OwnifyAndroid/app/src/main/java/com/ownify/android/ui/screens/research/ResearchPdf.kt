package com.ownify.android.ui.screens.research

import android.content.Context
import android.graphics.Bitmap
import android.graphics.pdf.PdfRenderer
import android.os.ParcelFileDescriptor
import android.util.LruCache
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.calculateCentroid
import androidx.compose.foundation.gestures.calculateZoom
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.shape.RoundedCornerShape
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.data.ResearchCopy
import com.ownify.android.data.ResearchPdf
import com.ownify.android.i18n.OwnifyFormat
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.Spinner
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.well
import com.ownify.android.ui.screens.settings.SettingsNote
import com.ownify.android.ui.theme.Ownify
import java.io.Closeable
import java.io.File
import java.net.ConnectException
import java.net.HttpURLConnection
import java.net.SocketTimeoutException
import java.net.URI
import java.net.UnknownHostException
import java.security.MessageDigest
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext

/*
 * Reading a publication's PDF in the app (docs/RESEARCH.md): the website
 * reads it in a frame where the browser can; the phone draws it itself,
 * with Android's PdfRenderer, in the same Well. Fetched once into the app's
 * cache — the device fetches the publisher's own file, as a browser would,
 * and Ownify's own copies from the server it talks to — never kept anywhere
 * else. Offline, nothing loads and the detail says why; a file that will not
 * load says so, and the publication is a tap away at its publisher.
 */

internal object ResearchPdfs {

    sealed interface Fetched {
        data class Ready(val file: File) : Fetched
        data object Offline : Fetched
        data object Failed : Fetched
    }

    /** The largest PDF read: the guidelines Ownify uses are a few megabytes. */
    private const val MAX_BYTES = 40L * 1024 * 1024

    /** Where the PDF is: the publisher's own address, or Ownify's copy on the server the app talks to. */
    fun address(pdf: ResearchPdf): String? {
        val href = pdf.href ?: return null
        return if (href.startsWith("https://")) href else OwnifyConnection.api.siteUrl + href
    }

    /** The PDF at [url] in the cache, fetched first when it is not there yet. */
    suspend fun fetch(context: Context, url: String): Fetched = withContext(Dispatchers.IO) {
        val dir = File(context.cacheDir, "research").apply { mkdirs() }
        val name = MessageDigest.getInstance("SHA-256").digest(url.toByteArray()).joinToString("") { "%02x".format(it) }.take(32)
        val file = File(dir, "$name.pdf")
        if (file.isFile && file.length() > 5) return@withContext Fetched.Ready(file)

        val part = File(dir, "$name.part")
        try {
            val connection = URI.create(url).toURL().openConnection() as HttpURLConnection
            try {
                connection.connectTimeout = 15_000
                connection.readTimeout = 30_000
                connection.instanceFollowRedirects = true
                connection.setRequestProperty("Accept", "application/pdf")
                if (connection.responseCode != HttpURLConnection.HTTP_OK || connection.contentLengthLong > MAX_BYTES) {
                    return@withContext Fetched.Failed
                }
                var total = 0L
                connection.inputStream.use { input ->
                    part.outputStream().use { out ->
                        val buffer = ByteArray(16 * 1024)
                        while (true) {
                            val read = input.read(buffer)
                            if (read < 0) break
                            total += read
                            if (total > MAX_BYTES) {
                                part.delete()
                                return@withContext Fetched.Failed
                            }
                            out.write(buffer, 0, read)
                        }
                    }
                }
            } finally {
                connection.disconnect()
            }
            // A PDF starts with its signature; a publisher's error page in its place is not one.
            val head = ByteArray(5)
            val got = part.inputStream().use { it.read(head) }
            if (got != 5 || String(head, Charsets.US_ASCII) != "%PDF-" || !part.renameTo(file)) {
                part.delete()
                return@withContext Fetched.Failed
            }
            Fetched.Ready(file)
        } catch (e: UnknownHostException) {
            part.delete()
            Fetched.Offline
        } catch (e: ConnectException) {
            part.delete()
            Fetched.Offline
        } catch (e: SocketTimeoutException) {
            part.delete()
            Fetched.Offline
        } catch (e: Exception) {
            part.delete()
            Fetched.Failed
        }
    }

    /** A file that would not open is not kept: the next try fetches it again. */
    fun forget(file: File) {
        file.delete()
    }
}

/**
 * One PDF open for drawing: its pages' sizes, and each page drawn on request
 * — one at a time, as PdfRenderer allows one open page — kept for a while
 * in a cache the size of an eighth of the app's memory.
 */
internal class PdfPages private constructor(
    private val descriptor: ParcelFileDescriptor,
    private val renderer: PdfRenderer,
    val sizes: List<IntSize>
) : Closeable {

    private val lock = Mutex()
    private var closed = false
    private val drawn = object : LruCache<String, Bitmap>(
        (Runtime.getRuntime().maxMemory() / 8).coerceAtMost(64L * 1024 * 1024).toInt()
    ) {
        override fun sizeOf(key: String, value: Bitmap): Int = value.byteCount
    }

    /** Page [index] [width] pixels wide, from the cache or drawn now; null when it cannot be. */
    suspend fun page(index: Int, width: Int): Bitmap? {
        val key = "$index@$width"
        drawn.get(key)?.let { return it }
        return lock.withLock {
            if (closed) return@withLock null
            withContext(Dispatchers.IO) {
                runCatching {
                    renderer.openPage(index).use { page ->
                        val height = (width.toLong() * page.height / page.width.coerceAtLeast(1)).toInt().coerceAtLeast(1)
                        val bitmap = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
                        // The document's own paper: a PDF is printed on white, in either theme.
                        bitmap.eraseColor(android.graphics.Color.WHITE)
                        page.render(bitmap, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                        bitmap
                    }
                }.getOrNull()?.also { drawn.put(key, it) }
            }
        }
    }

    /** Closed once nothing is being drawn. */
    suspend fun release() = lock.withLock {
        if (closed) return@withLock
        closed = true
        drawn.evictAll()
        runCatching { renderer.close() }
        runCatching { descriptor.close() }
    }

    override fun close() {
        CoroutineScope(Dispatchers.IO).launch { release() }
    }

    companion object {
        /** [file] opened, or null when it is not a PDF PdfRenderer can draw. */
        suspend fun open(file: File): PdfPages? = withContext(Dispatchers.IO) {
            runCatching {
                val descriptor = ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY)
                try {
                    val renderer = PdfRenderer(descriptor)
                    val sizes = (0 until renderer.pageCount).map { i -> renderer.openPage(i).use { IntSize(it.width, it.height) } }
                    if (sizes.isEmpty()) {
                        renderer.close()
                        error("no pages")
                    }
                    PdfPages(descriptor, renderer, sizes)
                } catch (e: Throwable) {
                    descriptor.close()
                    throw e
                }
            }.getOrNull()
        }
    }
}

private sealed interface Reading {
    data object Loading : Reading
    data class Ready(val pages: PdfPages) : Reading
    data object Failed : Reading
}

/**
 * The PDF in the detail (`.research-detail__frame`): a Well most of the
 * screen tall (78%, at most 900), its pages one under the other on their
 * paper, scrolled inside it. Two fingers zoom up to three times, a double
 * tap goes between whole and twice; zoomed in, a page also scrolls sideways.
 * Under it, which page you are on. Loading, a quiet spinner; offline, it
 * hands back to the detail, which says so ([onOffline]).
 */
@Composable
internal fun PdfReader(
    url: String,
    attempt: Int,
    copy: ResearchCopy,
    title: String,
    language: String,
    onOffline: (Boolean) -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    val offline by rememberUpdatedState(onOffline)
    var reading by remember(url) { mutableStateOf<Reading>(Reading.Loading) }

    LaunchedEffect(url, attempt) {
        if (reading is Reading.Ready) return@LaunchedEffect
        reading = Reading.Loading
        reading = when (val fetched = ResearchPdfs.fetch(context, url)) {
            is ResearchPdfs.Fetched.Ready -> {
                offline(false)
                PdfPages.open(fetched.file)?.let { Reading.Ready(it) } ?: run {
                    ResearchPdfs.forget(fetched.file)
                    Reading.Failed
                }
            }
            ResearchPdfs.Fetched.Offline -> {
                offline(true)
                Reading.Failed
            }
            ResearchPdfs.Fetched.Failed -> Reading.Failed
        }
    }

    val ready = reading as? Reading.Ready
    DisposableEffect(ready) {
        onDispose { ready?.pages?.close() }
    }

    when (val state = reading) {
        Reading.Loading -> Box(
            modifier.fillMaxWidth().height(frameHeight()).well(),
            contentAlignment = Alignment.Center
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Spinner(20.dp)
                T(copy.pdfLoading, JStyle.Footnote, Modifier.padding(top = Ownify.Space2), align = TextAlign.Center)
            }
        }
        Reading.Failed -> SettingsNote("info", copy.pdfFailed, modifier)
        is Reading.Ready -> Pages(state.pages, copy, title, language, modifier)
    }
}

/** The Well's height: 78% of the screen, at most 900. */
@Composable
private fun frameHeight() = minOf(LocalScreen.current.height * 0.78f, 900.dp)

@Composable
private fun Pages(pages: PdfPages, copy: ResearchCopy, title: String, language: String, modifier: Modifier) {
    val density = LocalDensity.current
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    val across = rememberScrollState()
    var zoom by remember { mutableFloatStateOf(1f) }
    val total = pages.sizes.size
    val shown by remember { derivedStateOf { (list.firstVisibleItemIndex + 1).coerceIn(1, total) } }

    Column(modifier.fillMaxWidth()) {
        BoxWithConstraints(
            Modifier
                .fillMaxWidth()
                .height(frameHeight())
                .well()
                .semantics { contentDescription = title }
        ) {
            val frame = with(density) { maxWidth.toPx() }

            /* Zoom about the point between the fingers: the page grows, the
               scrolls move so that point stays under them. */
            fun zoomTo(next: Float, at: Offset) {
                val to = next.coerceIn(1f, 3f)
                val factor = to / zoom
                if (factor == 1f) return
                val x = (across.value + at.x) * factor - at.x
                val y = (list.firstVisibleItemScrollOffset + at.y) * factor - at.y
                zoom = to
                scope.launch {
                    across.scrollTo(x.toInt().coerceAtLeast(0))
                    list.scrollToItem(list.firstVisibleItemIndex, y.toInt().coerceAtLeast(0))
                }
            }

            Box(
                Modifier
                    .fillMaxSize()
                    // Two fingers: theirs alone — the scrolls wait until they lift.
                    .pointerInput(Unit) {
                        awaitEachGesture {
                            awaitFirstDown(requireUnconsumed = false)
                            do {
                                val event = awaitPointerEvent(PointerEventPass.Initial)
                                if (event.changes.count { it.pressed } >= 2) {
                                    val change = event.calculateZoom()
                                    if (change != 1f) zoomTo(zoom * change, event.calculateCentroid(useCurrent = true))
                                    event.changes.forEach { it.consume() }
                                }
                            } while (event.changes.any { it.pressed })
                        }
                    }
                    .pointerInput(Unit) {
                        detectTapGestures(onDoubleTap = { at -> zoomTo(if (zoom > 1.25f) 1f else 2f, at) })
                    }
                    .horizontalScroll(across, enabled = zoom > 1f)
            ) {
                val width = with(density) { (frame * zoom).toDp() }
                LazyColumn(
                    Modifier.width(width).fillMaxHeight(),
                    state = list,
                    contentPadding = PaddingValues(Ownify.Space2),
                    verticalArrangement = Arrangement.spacedBy(Ownify.Space2)
                ) {
                    items(total) { index ->
                        Page(pages, index, pixels = ((frame * zoom) - with(density) { (Ownify.Space2 * 2).toPx() }).toInt(), label = page(copy, index + 1, total), language = language)
                    }
                }
            }
        }
        T(page(copy, shown, total), JStyle.Footnote, Modifier.fillMaxWidth().padding(top = Ownify.Space2), align = TextAlign.Center)
    }
}

private fun page(copy: ResearchCopy, n: Int, of: Int): String =
    runCatching { String.format(OwnifyFormat.locale(), copy.page, n, of) }.getOrDefault("$n / $of")

/**
 * One page on its paper: the size it will be from the start, drawn as wide
 * as it is shown — in steps of 256 pixels, at most 2048, so a pinch does not
 * draw it again at every frame — the earlier drawing shown until the new one
 * is there.
 */
@Composable
private fun Page(pages: PdfPages, index: Int, pixels: Int, label: String, language: String) {
    val size = pages.sizes[index]
    val step = (((pixels.coerceIn(256, 2048)) + 255) / 256) * 256
    var bitmap by remember(index) { mutableStateOf<ImageBitmap?>(null) }
    LaunchedEffect(index, step) {
        pages.page(index, step)?.let { bitmap = it.asImageBitmap() }
    }
    Box(
        Modifier
            .fillMaxWidth()
            .aspectRatio(size.width.toFloat() / size.height.coerceAtLeast(1))
            .clip(RoundedCornerShape(2.dp))
            .background(Ownify.fill(0.07f))
            .semantics { contentDescription = label }
    ) {
        bitmap?.let { Image(it, contentDescription = null, modifier = Modifier.fillMaxSize(), contentScale = ContentScale.FillBounds) }
    }
}
