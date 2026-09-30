package com.ownify.android.ui

import android.graphics.Bitmap
import com.ownify.android.connection.FakeOwnifyServer
import com.ownify.android.connection.OwnifyApi
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.ui.app.Avatars
import java.io.ByteArrayOutputStream
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Profile pictures are stored as they were uploaded, up to 3 MB, and a board
 * shows up to fifty: each is decoded smaller, never below 384 on its shorter
 * side — so a board of pictures does not take the memory of fifty photos.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [36])
class AvatarsTest {

    private lateinit var server: FakeOwnifyServer

    @Before
    fun setUp() {
        server = FakeOwnifyServer()
        OwnifyConnection.api = OwnifyApi(server.url)
        Avatars.forget()
    }

    @After
    fun tearDown() {
        server.stop()
        Avatars.forget()
    }

    private fun png(width: Int, height: Int): ByteArray {
        val bitmap = Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888)
        bitmap.eraseColor(android.graphics.Color.rgb(60, 158, 114))
        return ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }.toByteArray()
    }

    @Test
    fun `a large picture is decoded at a fraction - halved while its shorter side stays 384 or more`() = runBlocking {
        server.files["/uploads/avatars/big.png"] = png(1600, 1200)
        val image = Avatars.load("uploads/avatars/big.png")!!
        assertEquals(800, image.width)
        assertEquals(600, image.height)
    }

    @Test
    fun `a small picture is decoded as it is`() = runBlocking {
        server.files["/uploads/avatars/small.png"] = png(300, 300)
        val image = Avatars.load("uploads/avatars/small.png")!!
        assertEquals(300, image.width)
        assertEquals(300, image.height)
    }

    @Test
    fun `a picture the server does not have is nothing - the initial stays`() = runBlocking {
        assertNull(Avatars.load("uploads/avatars/gone.png"))
    }
}
