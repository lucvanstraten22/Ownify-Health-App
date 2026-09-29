package com.ownify.android.ui.screens.welcome

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyState
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.CardStyle
import com.ownify.android.ui.design.IconTile
import com.ownify.android.ui.design.JCard
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.OwnifyIcons
import com.ownify.android.ui.design.LocalScreen
import com.ownify.android.ui.design.T
import com.ownify.android.ui.screens.settings.PhoneSection
import com.ownify.android.ui.screens.settings.SyncNowButton
import com.ownify.android.ui.screens.settings.SyncResult
import com.ownify.android.ui.screens.settings.rememberPhoneHealth
import com.ownify.android.ui.theme.Ownify

/**
 * A phone paired with a code but not signed in (Android-only, see
 * docs/PARITY.md): under the opening screen's line, what it is paired with
 * and how its Health Connect sync stands, with the same controls as
 * Instellingen › Apparaten & Gezondheid. Signing in, below, makes it the
 * account's own phone; the sync carries on.
 */
@Composable
fun PairedStatus() {
    val context = LocalContext.current
    val screen = LocalScreen.current
    val state = OwnifyConnection.state

    JCard(Modifier.width(screen.shell).padding(top = Ownify.Space5), style = CardStyle.Quiet) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)) {
            IconTile(OwnifyIcons.pulse, color = Ownify.Health)
            Column(Modifier.weight(1f)) {
                val who = (state as? OwnifyState.Connected)?.profile?.username
                T(if (who != null) "Gekoppeld aan $who" else "Deze telefoon is gekoppeld", JStyle.Eyebrow, Modifier.semantics { heading() })
                T("Met een koppelcode. Log in om ook je account te gebruiken.", JStyle.Tiny, Modifier.padding(top = Ownify.Space1))
            }
        }

        if (state is OwnifyState.Failed) {
            T(state.message, JStyle.Meta, Modifier.padding(top = Ownify.Space4))
            Btn("Opnieuw proberen", onClick = { OwnifyConnection.retry(context) }, modifier = Modifier.fillMaxWidth().padding(top = Ownify.Space4))
        } else {
            PhoneSection(rememberPhoneHealth(), Modifier.padding(top = Ownify.Space4))
            SyncNowButton(Modifier.fillMaxWidth().padding(top = Ownify.Space4))
            SyncResult()
        }
    }
}
