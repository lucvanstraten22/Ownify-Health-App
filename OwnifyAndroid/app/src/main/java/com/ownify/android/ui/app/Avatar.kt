package com.ownify.android.ui.app

import android.graphics.BitmapFactory
import android.util.LruCache
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.Dp
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.design.JIcon
import com.ownify.android.ui.design.OwnifyIcons
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Deferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.async
import kotlinx.coroutines.withContext

/**
 * Profile pictures, as the website shows them: the server serves them as
 * plain files (uploads/avatars/…), fetched without the token and kept in
 * memory only — a board's worth at a time.
 *
 * A picture is stored as it was uploaded (up to 3 MB), and a board shows up
 * to fifty of them in small circles, so each is decoded at a fraction of its
 * size: never smaller than [MAX_SIDE] on its shorter side, which is more than
 * the largest circle it is drawn in.
 */
object Avatars {
    private const val MAX_SIDE = 384

    private val cache = LruCache<String, ImageBitmap>(64)

    /**
     * The downloads under way, one per picture: somebody on three boards is
     * three rows asking at once, and one download answers all of them.
     */
    private val loading = HashMap<String, Deferred<ImageBitmap?>>()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    fun cached(path: String): ImageBitmap? = cache.get(path)

    suspend fun load(path: String): ImageBitmap? {
        cache.get(path)?.let { return it }
        val download = synchronized(loading) {
            loading[path] ?: scope.async(start = CoroutineStart.LAZY) { fetch(path) }.also { loading[path] = it }
        }
        download.start()
        try {
            return download.await()
        } finally {
            synchronized(loading) { if (loading[path] === download) loading.remove(path) }
        }
    }

    private suspend fun fetch(path: String): ImageBitmap? {
        val bytes = OwnifyConnection.api.image(path) ?: return null
        val bitmap = withContext(Dispatchers.Default) {
            runCatching {
                val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
                var sample = 1
                while (minOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= MAX_SIDE) sample *= 2
                val options = BitmapFactory.Options().apply { inSampleSize = sample }
                BitmapFactory.decodeByteArray(bytes, 0, bytes.size, options)?.asImageBitmap()
            }.getOrNull()
        } ?: return null
        cache.put(path, bitmap)
        return bitmap
    }

    /** A new picture was uploaded: the next read shows it, not the old copy. */
    fun forget() = cache.evictAll()
}

/**
 * A profile picture filling its circle (`object-fit: cover`), or the user
 * icon while there is none — or while it is on its way.
 */
@Composable
fun Avatar(path: String?, iconSize: Dp, iconColor: Color, modifier: Modifier = Modifier) {
    var image by remember(path) { mutableStateOf(path?.let(Avatars::cached)) }

    LaunchedEffect(path) {
        if (path != null && image == null) image = Avatars.load(path)
    }

    Box(modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        val shown = image
        if (shown != null) {
            Image(shown, contentDescription = null, modifier = Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
        } else {
            JIcon(OwnifyIcons.user, size = iconSize, color = iconColor)
        }
    }
}

/**
 * Only the picture, once it is here (`.board-row__photo`): nothing while it
 * is on its way or if it cannot be had, so whatever is drawn under it — the
 * initial on a board row — shows until then.
 */
@Composable
fun AvatarPhoto(path: String, modifier: Modifier = Modifier) {
    var image by remember(path) { mutableStateOf(Avatars.cached(path)) }

    LaunchedEffect(path) {
        if (image == null) image = Avatars.load(path)
    }

    image?.let { Image(it, contentDescription = null, modifier = modifier.fillMaxSize(), contentScale = ContentScale.Crop) }
}
