package com.ownify.android.ui

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Matrix
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffColorFilter
import android.graphics.PorterDuffXfermode
import android.graphics.RectF
import android.graphics.drawable.AdaptiveIconDrawable
import android.graphics.drawable.BitmapDrawable
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.Drawable
import androidx.core.graphics.PathParser
import androidx.test.core.app.ApplicationProvider
import com.ownify.android.R
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import java.io.File
import java.io.FileOutputStream
import kotlin.math.abs
import kotlin.math.hypot
import kotlin.math.roundToInt

/**
 * The launcher icon is the Ownify logo (docs/BRANDING.md): adaptive, on the
 * full logo's dark green, the symbol centred and wholly inside the 66 dp
 * safe zone at every density - so no launcher's mask can clip it.
 *
 * With -Downify.shots=<dir> it also draws the icon the way launchers do -
 * through AdaptiveIconDrawable itself, and under the other common mask
 * shapes - with the themed (monochrome) version and the splash, to look at.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [36])
class BrandIconTest {

    private val context: Context get() = ApplicationProvider.getApplicationContext()

    private fun icon(id: Int): AdaptiveIconDrawable {
        val drawable = context.getDrawable(id)
        assertTrue("$id is an adaptive icon", drawable is AdaptiveIconDrawable)
        return drawable as AdaptiveIconDrawable
    }

    @Test
    fun `the launcher icon is the adaptive Ownify logo, on the logo's own dark green`() {
        val app = context.packageManager.getApplicationInfo(context.packageName, 0)
        assertEquals(R.mipmap.ic_launcher, app.icon)

        for (id in listOf(R.mipmap.ic_launcher, R.mipmap.ic_launcher_round)) {
            val icon = icon(id)
            assertEquals(0xFF101C15.toInt(), (icon.background as ColorDrawable).color)
            assertTrue("the foreground is the logo's bitmap", icon.foreground is BitmapDrawable)
            assertNotNull("themed icons get the same shape", icon.monochrome)
        }
    }

    @Test
    fun `at every density the symbol is centred and inside the safe zone`() {
        for ((qualifier, scale) in listOf("mdpi" to 1f, "hdpi" to 1.5f, "xhdpi" to 2f, "xxhdpi" to 3f, "xxxhdpi" to 4f)) {
            RuntimeEnvironment.setQualifiers("+$qualifier")
            val bitmap = (icon(R.mipmap.ic_launcher).foreground as BitmapDrawable).bitmap
            val side = (108 * scale).roundToInt()
            assertEquals("$qualifier: the 108 dp layer", side, bitmap.width)

            var x0 = side; var x1 = -1; var y0 = side; var y1 = -1
            var reach = 0.0
            val c = side / 2.0
            for (y in 0 until side) for (x in 0 until side) {
                val a = Color.alpha(bitmap.getPixel(x, y))
                // Visible pixels: Lanczos leaves a few at 1% alpha just past the edge.
                if (a >= 8) reach = maxOf(reach, hypot(x + 0.5 - c, y + 0.5 - c))
                if (a > 127) {
                    x0 = minOf(x0, x); x1 = maxOf(x1, x); y0 = minOf(y0, y); y1 = maxOf(y1, y)
                }
            }
            val dx = (x0 + x1 + 1) / 2.0 - c
            val dy = (y0 + y1 + 1) / 2.0 - c
            assertTrue("$qualifier: centred ($dx, $dy px)", abs(dx) <= 0.5 && abs(dy) <= 0.5)
            val safe = 33.0 / 108.0 * side
            assertTrue("$qualifier: everything visible within the 66 dp safe zone ($reach of $safe px)", reach <= safe)
            val height = (y1 - y0 + 1) / (72.0 / 108.0 * side)
            assertTrue("$qualifier: the symbol about 78% of the visible icon (${height * 100}%)", height in 0.76..0.80)
        }
    }

    // ------------------------------------------------------------ pictures

    private val out = System.getProperty("ownify.shots").orEmpty()

    @Test
    fun `pictures - the icon as launchers, themed icons and the splash show it`() {
        if (out.isEmpty()) return
        File(out).mkdirs()
        RuntimeEnvironment.setQualifiers("+xxxhdpi")
        val icon = icon(R.mipmap.ic_launcher)
        val n = 288                                            // 72 dp at xxxhdpi

        // 1. The framework's own drawing, with this system's mask.
        save("launcher-system.png", Bitmap.createBitmap(n, n, Bitmap.Config.ARGB_8888).also {
            icon.setBounds(0, 0, n, n)
            icon.draw(Canvas(it))
        })

        // 2. The masks launchers choose from (AOSP's shapes, in a 100 x 100 box).
        val masks = mapOf(
            "circle" to "M50 0A50 50,0,1,1,50 100A50 50,0,1,1,50 0",
            "squircle" to "M50,0 C10,0 0,10 0,50 0,90 10,100 50,100 90,100 100,90 100,50 100,10 90,0 50,0 Z",
            "rounded-square" to "M50,0L88,0 C94.4,0 100,5.4 100,12 L100,88 C100,94.6 94.6,100 88,100 L12,100 C5.4,100 0,94.6 0,88 L0,12 C0,5.4 5.4,0 12,0 L50,0 Z",
            "teardrop" to "M50,0 A50,50,0,0,1 100,50 L100,85 A15,15,0,0,1 85,100 L50,100 A50,50,0,0,1 50,0z",
            "pebble" to "M55,0 C25,0 0,25 0,50 0,78 28,100 55,100 85,100 100,80 100,50 100,20 85,0 55,0 Z",
        )
        for ((name, d) in masks) save("launcher-$name.png", masked(icon.background, icon.foreground, d, n))

        // The safe zone and the visible area, drawn over the layers, unmasked.
        save("launcher-guides.png", Bitmap.createBitmap(n * 3 / 2, n * 3 / 2, Bitmap.Config.ARGB_8888).also {
            val canvas = Canvas(it)
            val m = n * 3 / 2
            icon.background.setBounds(0, 0, m, m); icon.background.draw(canvas)
            icon.foreground.setBounds(0, 0, m, m); icon.foreground.draw(canvas)
            val line = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE; strokeWidth = 2f }
            line.color = Color.argb(200, 255, 80, 80)
            canvas.drawCircle(m / 2f, m / 2f, m * 33f / 108f, line)            // 66 dp safe zone
            line.color = Color.argb(200, 80, 160, 255)
            canvas.drawRect(m / 6f, m / 6f, m * 5f / 6f, m * 5f / 6f, line)   // 72 dp visible
        })

        // 3. Themed icons (Android 13+): the monochrome layer's shape in the system's tones.
        for ((name, tones) in mapOf("themed-light" to (0xFFD7E8DA.toInt() to 0xFF23412C.toInt()), "themed-dark" to (0xFF1E2A21.toInt() to 0xFFB9D3BF.toInt()))) {
            save("launcher-$name.png", masked(ColorDrawable(tones.first), icon.monochrome!!.mutate().apply {
                colorFilter = PorterDuffColorFilter(tones.second, PorterDuff.Mode.SRC_IN)
            }, masks.getValue("circle"), n))
        }

        // 4. The system splash (Android 12+, no splash attributes set): the
        // launcher icon in a 160 dp circle on the window's colour.
        for ((name, ground) in mapOf("splash-dark" to 0xFF252223.toInt(), "splash-light" to 0xFFF0EDEE.toInt())) {
            val w = 412 * 4 / 2; val h = 915 * 4 / 2                     // a 412 x 915 dp phone, at 2x
            save("$name.png", Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888).also {
                val canvas = Canvas(it)
                canvas.drawColor(ground)
                val size = 160 * 2
                val badge = masked(icon.background, icon.foreground, masks.getValue("circle"), size)
                canvas.drawBitmap(badge, (w - size) / 2f, (h - size) / 2f, Paint(Paint.FILTER_BITMAP_FLAG))
            })
        }
    }

    /** The two layers as a launcher composes them - 108 dp drawn round the 72 dp seen - cut by [mask]. */
    private fun masked(background: Drawable, foreground: Drawable, mask: String, n: Int): Bitmap {
        val layers = Bitmap.createBitmap(n, n, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(layers)
        val inset = n / 4
        background.setBounds(-inset, -inset, n + inset, n + inset); background.draw(canvas)
        foreground.setBounds(-inset, -inset, n + inset, n + inset); foreground.draw(canvas)
        val path = PathParser.createPathFromPathData(mask)
        path.transform(Matrix().apply { setRectToRect(RectF(0f, 0f, 100f, 100f), RectF(0f, 0f, n.toFloat(), n.toFloat()), Matrix.ScaleToFit.FILL) })
        val shape = Bitmap.createBitmap(n, n, Bitmap.Config.ARGB_8888)
        Canvas(shape).drawPath(path, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.BLACK })
        canvas.drawBitmap(shape, 0f, 0f, Paint().apply { xfermode = PorterDuffXfermode(PorterDuff.Mode.DST_IN) })
        return layers
    }

    private fun save(name: String, bitmap: Bitmap) {
        FileOutputStream(File(out, name)).use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }
}
