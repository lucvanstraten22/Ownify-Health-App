package com.ownify.android.connection

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

/**
 * What a Ownify token may do — the server's `user_devices.scope`, kept next to
 * the token so the app always knows which one it holds. The server decides
 * what a token may do whatever the phone thinks; this is only so the app
 * offers the right things.
 */
enum class OwnifyScope {
    /** From a pairing code: upload Health Connect records, read the profile and targets. Nothing else. */
    SYNC,

    /** From signing in (or registering) in the app: all of that, and act as the account. */
    ACCOUNT
}

/**
 * The one Ownify credential this phone holds. One, not one per scope: signing
 * in on a paired phone turns that phone's row on the server into the account
 * row, with a new token, and the old sync token stops working (see
 * docs/APP-AUTH.md) — so there is never a second token worth keeping.
 */
class OwnifyCredential(val token: String, val scope: OwnifyScope) {

    override fun equals(other: Any?): Boolean =
        other is OwnifyCredential && other.token == token && other.scope == scope

    override fun hashCode(): Int = 31 * token.hashCode() + scope.hashCode()

    /** Never the token: a credential that ends up in a log or a crash report must not carry it. */
    override fun toString(): String = "OwnifyCredential(scope=$scope)"
}

/** Where the credential is kept. [OwnifyTokenStore] on the phone; tests keep it in memory. */
internal interface OwnifyTokenStorage {
    fun load(): OwnifyCredential?
    fun save(credential: OwnifyCredential)
    fun clear()
}

/** Turns the token into something only this phone can read back, and back again. */
internal interface OwnifyTokenCipher {
    fun encrypt(plain: String): String
    fun decrypt(stored: String): String
}

/**
 * Keeps this phone's Ownify credential between launches — only the token and
 * its scope: no password, no user id, no profile.
 *
 * The token is encrypted with AES-256-GCM under a key that is generated inside
 * the Android Keystore and can never be read out of it (on most phones it
 * lives in secure hardware). What lands in the app's private preferences is
 * only that ciphertext, useless anywhere else: in a backup, on another phone,
 * or read straight off the file system. The preferences file is kept out of
 * backups and device transfers as well (res/xml/backup_rules.xml and
 * data_extraction_rules.xml).
 *
 * The scope is stored beside it in plain text: it is not a secret, and the
 * server enforces it regardless. A token stored before scopes existed has
 * none, and was made by pairing — so it reads as [OwnifyScope.SYNC], and a phone
 * paired before this version carries on syncing untouched.
 *
 * Every call touches the Keystore or the disk: call from a background thread.
 */
internal class OwnifyTokenStore(
    context: Context,
    private val cipher: OwnifyTokenCipher = KeystoreTokenCipher
) : OwnifyTokenStorage {

    private val prefs = context.applicationContext
        .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    /**
     * The stored credential, or null when there is none. A value that can no
     * longer be decrypted (its Keystore key is gone) reads as none too;
     * pairing or signing in again overwrites it.
     */
    override fun load(): OwnifyCredential? =
        try {
            prefs.getString(KEY_TOKEN, null)?.let { stored ->
                OwnifyCredential(cipher.decrypt(stored), scopeOf(prefs.getString(KEY_SCOPE, null)))
            }
        } catch (e: Exception) {
            null
        }

    /** Token and scope in one write. Throws when it cannot be encrypted or written; nothing changes then. */
    override fun save(credential: OwnifyCredential) {
        val written = prefs.edit()
            .putString(KEY_TOKEN, cipher.encrypt(credential.token))
            .putString(KEY_SCOPE, credential.scope.name.lowercase())
            .commit()

        check(written) { "The Ownify token could not be written." }
    }

    /** Written at once, so the token is gone before the screen asks for a new one. */
    override fun clear() {
        prefs.edit(commit = true) {
            remove(KEY_TOKEN)
            remove(KEY_SCOPE)
        }
    }

    private fun scopeOf(stored: String?): OwnifyScope =
        if (stored == "account") OwnifyScope.ACCOUNT else OwnifyScope.SYNC

    private companion object {
        /** shared_prefs/ownify_connection.xml — the name the backup rules exclude. */
        const val PREFS_NAME = "ownify_connection"
        const val KEY_TOKEN = "device_token"
        const val KEY_SCOPE = "token_scope"
    }
}

/** AES-256-GCM under a key that never leaves the Android Keystore. */
internal object KeystoreTokenCipher : OwnifyTokenCipher {

    private const val KEYSTORE = "AndroidKeyStore"
    private const val KEY_ALIAS = "ownify_device_token_key"
    private const val TRANSFORMATION = "AES/GCM/NoPadding"
    private const val TAG_BITS = 128
    private const val SEPARATOR = ":"

    /** "iv:ciphertext", both Base64. The Keystore picks a fresh random IV every time. */
    override fun encrypt(plain: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key())

        val ciphertext = cipher.doFinal(plain.toByteArray(Charsets.UTF_8))
        val base64 = Base64.getEncoder()

        return base64.encodeToString(cipher.iv) + SEPARATOR + base64.encodeToString(ciphertext)
    }

    override fun decrypt(stored: String): String {
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
}
