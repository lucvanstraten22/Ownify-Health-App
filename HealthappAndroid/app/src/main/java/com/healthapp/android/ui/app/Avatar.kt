package com.healthapp.android.ui.app

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
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JoluIcons
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Profile pictures, as the website shows them: the server serves them as
 * plain files (uploads/avatars/…), fetched without the token and kept in
 * memory only, a few at a time.
 */
object Avatars {
    private val cache = LruCache<String, ImageBitmap>(24)

    fun cached(path: String): ImageBitmap? = cache.get(path)

    suspend fun load(path: String): ImageBitmap? {
        cache.get(path)?.let { return it }
        val bytes = JoluConnection.api.image(path) ?: return null
        val bitmap = withContext(Dispatchers.Default) {
            runCatching { BitmapFactory.decodeByteArray(bytes, 0, bytes.size)?.asImageBitmap() }.getOrNull()
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
            JIcon(JoluIcons.user, size = iconSize, color = iconColor)
        }
    }
}
