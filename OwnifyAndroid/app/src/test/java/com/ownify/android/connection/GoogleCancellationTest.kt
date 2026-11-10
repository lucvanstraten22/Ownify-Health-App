package com.ownify.android.connection

import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * 45.0: what Credential Manager calls a cancellation. The person closing
 * Google's chooser stays silent; Google refusing this build after an account
 * was chosen (Play services' status 16) says that signing in did not work,
 * instead of dropping the person back on the panel without a word.
 */
class GoogleCancellationTest {

    @Test
    fun closingTheChooserSaysNothing() {
        assertEquals(GoogleIdResult.Cancelled, CredentialManagerGoogle.cancelled("activity is cancelled by the user."))
        assertEquals(GoogleIdResult.Cancelled, CredentialManagerGoogle.cancelled(null))
        assertEquals(GoogleIdResult.Cancelled, CredentialManagerGoogle.cancelled(""))
    }

    @Test
    fun googleRefusingAfterTheAccountIsChosenIsAFailure() {
        assertEquals(GoogleIdResult.Failed, CredentialManagerGoogle.cancelled("16: Account reauth failed."))
        assertEquals(GoogleIdResult.Failed, CredentialManagerGoogle.cancelled("16: "))
    }
}
