package com.healthapp.android.jolu

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import androidx.core.content.edit
import java.security.KeyStore
import java.util.Base64
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** Where the device token is kept. [JoluTokenStore] on the phone; tests keep it in memory. */
internal interface JoluTokenStorage {
    fun load(): String?
    fun save(token: String)
    fun clear()
}

/**
 * Keeps this phone's JoLu device token between launches — only the token: no
 * password, no user id, no profile.
 *
 * The token is encrypted with AES-256-GCM under a key that is generated inside
 * the Android Keystore and can never be read out of it (on most phones it
 * lives in secure hardware). What lands in the app's private preferences is
 * only that ciphertext, useless anywhere else: in a backup, on another phone,
 * or read straight off the file system. The preferences file is kept out of
 * backups and device transfers as well (res/xml/backup_rules.xml and
 * data_extraction_rules.xml).
 *
 * Every call touches the Keystore or the disk: call from a background thread.
 */
internal class JoluTokenStore(context: Context) : JoluTokenStorage {

    private val prefs = context.applicationContext
        .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    /**
     * The stored token, or null when there is none. A value that can no longer
     * be decrypted (its Keystore key is gone) reads as none too; pairing again
     * overwrites it.
     */
    override fun load(): String? =
        try {
            prefs.getString(KEY_TOKEN, null)?.let { decrypt(it) }
        } catch (e: Exception) {
            null
        }

    /** Throws when the token cannot be encrypted or written; nothing is stored then. */
    override fun save(token: String) {
        val written = prefs.edit()
            .putString(KEY_TOKEN, encrypt(token))
            .commit()

        check(written) { "The JoLu token could not be written." }
    }

    /** Written at once, so the token is gone before the screen asks for a new code. */
    override fun clear() {
        prefs.edit(commit = true) {
            remove(KEY_TOKEN)
        }
    }

    /** "iv:ciphertext", both Base64. The Keystore picks a fresh random IV every time. */
    private fun encrypt(token: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key())

        val ciphertext = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        val base64 = Base64.getEncoder()

        return base64.encodeToString(cipher.iv) + SEPARATOR + base64.encodeToString(ciphertext)
    }

    private fun decrypt(stored: String): String {
        val parts = stored.split(SEPARATOR)
        require(parts.size == 2) { "Not a stored token." }

        val base64 = Base64.getDecoder()
        val iv = base64.decode(parts[0])
        val ciphertext = base64.decode(parts[1])

        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(TAG_BITS, iv))

        return String(cipher.doFinal(ciphertext), Charsets.UTF_8)
    }

    /** This app's AES key in the Android Keystore, created the first time it is needed. */
    private fun key(): SecretKey {
        val keyStore = KeyStore.getInstance(KEYSTORE).apply { load(null) }

        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE)
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build()
        )

        return generator.generateKey()
    }

    private companion object {
        /** shared_prefs/jolu_connection.xml — the name the backup rules exclude. */
        const val PREFS_NAME = "jolu_connection"
        const val KEY_TOKEN = "device_token"

        const val KEYSTORE = "AndroidKeyStore"
        const val KEY_ALIAS = "jolu_device_token_key"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val TAG_BITS = 128
        const val SEPARATOR = ":"
    }
}
