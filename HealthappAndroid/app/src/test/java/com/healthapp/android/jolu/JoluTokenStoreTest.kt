package com.healthapp.android.jolu

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import java.util.Base64
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * What JoluTokenStore keeps in shared_prefs/jolu_connection.xml, and how it
 * reads it back — above all that a phone paired before scopes existed reads
 * its token as the sync token it is. The JVM has no Android Keystore, so the
 * encryption is a stand-in; the file, the keys and the scope are real.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class JoluTokenStoreTest {

    private lateinit var context: Context

    /** Reversible, and visibly not the token. */
    private object TestCipher : JoluTokenCipher {
        override fun encrypt(plain: String) = "sealed:" + Base64.getEncoder().encodeToString(plain.reversed().toByteArray())
        override fun decrypt(stored: String): String {
            require(stored.startsWith("sealed:"))
            return String(Base64.getDecoder().decode(stored.removePrefix("sealed:"))).reversed()
        }
    }

    private val prefs get() = context.getSharedPreferences("jolu_connection", Context.MODE_PRIVATE)

    private fun store() = JoluTokenStore(context, TestCipher)

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        prefs.edit().clear().commit()
    }

    @Test
    fun `a token stored before scopes existed is the paired sync token it always was`() {
        prefs.edit().putString("device_token", TestCipher.encrypt(TEST_TOKEN)).commit()

        assertEquals(JoluCredential(TEST_TOKEN, JoluScope.SYNC), store().load())
    }

    @Test
    fun `an account credential survives a restart - a new store reads it back with its scope`() {
        store().save(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT))

        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), store().load())
        assertEquals("account", prefs.getString("token_scope", null))
    }

    @Test
    fun `saving replaces - one credential per phone`() {
        store().save(JoluCredential(TEST_TOKEN, JoluScope.SYNC))
        store().save(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT))

        assertEquals(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT), store().load())
        assertEquals(setOf("device_token", "token_scope"), prefs.all.keys)
    }

    @Test
    fun `the token is only ever stored sealed`() {
        store().save(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT))

        prefs.all.values.forEach { assertFalse(it.toString().contains(ACCOUNT_TOKEN)) }
    }

    @Test
    fun `clearing removes the token and its scope`() {
        store().save(JoluCredential(ACCOUNT_TOKEN, JoluScope.ACCOUNT))
        store().clear()

        assertNull(store().load())
        assertEquals(emptySet<String>(), prefs.all.keys)
    }

    @Test
    fun `a value that can no longer be read is no credential`() {
        prefs.edit().putString("device_token", "not sealed by this phone").putString("token_scope", "account").commit()

        assertNull(store().load())
    }
}
