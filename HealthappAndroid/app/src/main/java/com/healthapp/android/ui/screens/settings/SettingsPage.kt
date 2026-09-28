package com.healthapp.android.ui.screens.settings

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import com.healthapp.android.data.AppData
import com.healthapp.android.data.SettingsRow
import com.healthapp.android.jolu.JoluAuthState
import com.healthapp.android.jolu.JoluConnection
import com.healthapp.android.ui.app.Detail
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.app.Overlay
import com.healthapp.android.ui.app.PageColumn
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.Disclaimer
import com.healthapp.android.ui.design.JCard
import com.healthapp.android.ui.design.JIcon
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.PageIntro
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.design.cssPadding
import com.healthapp.android.ui.design.press
import com.healthapp.android.ui.design.reveal
import com.healthapp.android.ui.screens.account.AvatarCircle
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.InButton
import com.healthapp.android.ui.theme.JoluType

/**
 * Instellingen (pages/settings.php): who is signed in, then categories —
 * every row opens its own screen — and, below the groups and outside them,
 * signing out and deleting the account.
 */
@Composable
fun SettingsPage(data: AppData, scroll: ScrollState) {
    val settings = data.settings
    val shell = LocalShell.current
    val context = LocalContext.current
    val signedIn = data.auth.signedIn
    val signingOut = JoluConnection.auth is JoluAuthState.Working

    PageColumn(scroll) {
        PageIntro(settings.title, settings.lede, Modifier.reveal())

        IdentityCard(data) { shell.openDetail(Detail.SettingsPage("account")) }

        // The account group is the identity card above.
        settings.groups.filter { it.label != "Account" }.forEach { group ->
            Column(Modifier.fillMaxWidth().reveal()) {
                SettingsEyebrow(group.label)
                SettingsCard {
                    group.rows.forEachIndexed { i, row ->
                        SettingsRowButton(row, divided = i > 0) { shell.openDetail(Detail.SettingsPage(row.id)) }
                    }
                }
            }
        }

        // .settings-actions: not preferences, so outside the groups; 16 more above.
        Column(
            Modifier.fillMaxWidth().padding(top = Jolu.Space4).reveal(),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Jolu.Space3)
        ) {
            Btn(
                settings.logoutLabel,
                onClick = { JoluConnection.logout(context) },
                enabled = signedIn && !signingOut,
                icon = JoluIcons.logout,
                iconSize = 17.dp,
                modifier = Modifier.fillMaxWidth()
            )
            DeleteLink(settings.delete["label"].orEmpty(), enabled = signedIn) { shell.open(Overlay.DeleteAccount) }
        }

        if (data.disclaimer.isNotEmpty()) Disclaimer(data.disclaimer)
    }
}

/** `.settings-identity`: the account's picture, name and a line — the way into Account. */
@Composable
private fun IdentityCard(data: AppData, onClick: () -> Unit) {
    InButton {
        val narrow = LocalScreen.current.narrow
        val profile = data.settings.profile
        val signedIn = data.auth.signedIn
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val nudge by animateDpAsState(if (pressed) 2.dp else 0.dp, tween(Jolu.FastMs, easing = Jolu.Ease), label = "chevron")

        JCard(
            Modifier
                .fillMaxWidth()
                .reveal()
                .press(interaction, scale = 0.99f)
                .clickable(interaction, indication = null, onClick = onClick)
                .clearAndSetSemantics {
                    role = Role.Button
                    contentDescription = "Account openen"
                    onClick { onClick(); true }
                },
            padding = PaddingValues(horizontal = if (narrow) Jolu.Space4 else Jolu.Space5, vertical = Jolu.Space4)
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(if (narrow) Jolu.Space3 else Jolu.Space4)) {
                AvatarCircle(profile.avatar, if (narrow) 42.dp else 48.dp, 22.dp)
                Column(Modifier.weight(1f)) {
                    T(if (signedIn) profile.username.orEmpty() else "Niet ingelogd", JStyle.Subtitle, maxLines = 1, ellipsis = true)
                    T(
                        if (signedIn) "Profiel en gegevens beheren" else "Log in via de accountknop rechtsboven",
                        JStyle.Meta,
                        Modifier.padding(top = 2.dp)
                    )
                }
                JIcon(JoluIcons.chevronRight, Modifier.offset { IntOffset(nudge.roundToPx(), 0) }, size = 15.dp, color = Jolu.TextFaint)
            }
        }
    }
}

/** `.settings-row`: its mark, its name, the current value under it, a chevron. */
@Composable
private fun SettingsRowButton(row: SettingsRow, divided: Boolean, onClick: () -> Unit) {
    InButton {
        val narrow = LocalScreen.current.narrow
        val meta = row.value ?: row.hint
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val fill by animateColorAsState(if (pressed) Jolu.white(0.05f) else Color.Transparent, tween(Jolu.FastMs, easing = Jolu.Ease), label = "row")
        val nudge by animateDpAsState(if (pressed) 2.dp else 0.dp, tween(Jolu.FastMs, easing = Jolu.Ease), label = "chevron")

        Row(
            Modifier
                .press(interaction)
                .fillMaxWidth()
                .settingsRow(divided, fill)
                .clickable(interaction, indication = null, onClick = onClick)
                .clearAndSetSemantics {
                    role = Role.Button
                    contentDescription = row.label + (meta?.let { " — $it" } ?: "") + ". Open instellingen."
                    onClick { onClick(); true }
                }
                .cssPadding(if (narrow) Jolu.Space3 else Jolu.Space4, top = Jolu.Space3, bottom = Jolu.Space3, above = if (divided) 1.dp else 0.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(if (narrow) Jolu.Space2 else Jolu.Space3)
        ) {
            Mark(JoluIcons.named(row.icon))
            Column(Modifier.weight(1f)) {
                T(row.label, JoluType.style(Jolu.FsLabel, FontWeight.Medium))
                if (meta != null) T(meta, JStyle.Meta, Modifier.padding(top = 2.dp))
            }
            JIcon(JoluIcons.chevronRight, Modifier.offset { IntOffset(nudge.roundToPx(), 0) }, size = 15.dp, color = Jolu.TextFaint)
        }
    }
}

/** `.settings-row__mark`: a 30 square with a hairline, the row's icon in the secondary colour. */
@Composable
private fun Mark(icon: ImageVector?) {
    val shape = RoundedCornerShape(10.dp)
    Box(
        Modifier
            .size(30.dp)
            .clip(shape)
            .background(Jolu.white(0.055f))
            .border(1.dp, Jolu.GlassHairline, shape),
        contentAlignment = Alignment.Center
    ) {
        if (icon != null) JIcon(icon, size = 16.dp, color = Jolu.TextSecondary)
    }
}

/** `.settings-delete`: quiet, underlined, muted — and inert when there is no account to delete. */
@Composable
private fun DeleteLink(label: String, enabled: Boolean, onClick: () -> Unit) {
    InButton {
        val interaction = remember { MutableInteractionSource() }
        val pressed by interaction.collectIsPressedAsState()
        val color by animateColorAsState(if (pressed) Jolu.TextSecondary else Jolu.TextMuted, tween(Jolu.FastMs, easing = Jolu.Ease), label = "delete")
        T(
            label,
            JoluType.style(Jolu.FsSmall, color = color).copy(textDecoration = if (enabled) TextDecoration.Underline else TextDecoration.None),
            Modifier
                .padding(top = Jolu.Space2)
                .press(interaction, enabled = enabled)
                .alpha(if (enabled) 1f else 0.45f)
                .clip(RoundedCornerShape(50))
                .clickable(interaction, indication = null, enabled = enabled, role = Role.Button, onClick = onClick)
                .padding(horizontal = Jolu.Space4, vertical = Jolu.Space2)
        )
    }
}

/** `.settings-eyebrow`: small caps over a group, 8 in and 8 above the card. */
@Composable
fun SettingsEyebrow(text: String, modifier: Modifier = Modifier) {
    T(
        text,
        JStyle.Caption,
        modifier.fillMaxWidth().padding(horizontal = Jolu.Space2).padding(bottom = Jolu.Space2).semantics { heading() },
        uppercase = true
    )
}

/** `.settings-card`: the card without padding, its rows edge to edge, a hairline between them. */
@Composable
fun SettingsCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    JCard(modifier.fillMaxWidth(), padding = PaddingValues(0.dp), content = content)
}

/** A hairline between two parts of a card. */
@Composable
fun Hairline() {
    Box(Modifier.fillMaxWidth().height(1.dp).background(Jolu.GlassHairline))
}

/**
 * A settings card's row: `min-height: 56px` in `box-sizing: border-box`,
 * and on every row after the card's first (`.settings-card > * + *`) the
 * hairline as its top border, inside that height. A short row is 56 with
 * its line, as on the website; a taller one grows by the line.
 */
fun Modifier.settingsRow(divided: Boolean, fill: Color = Color.Transparent): Modifier =
    heightIn(min = 56.dp)
        .then(if (fill != Color.Transparent) Modifier.background(fill) else Modifier)
        .then(
            if (divided) {
                Modifier
                    .drawWithContent {
                        drawContent()
                        drawRect(Jolu.GlassHairline, size = Size(size.width, 1.dp.roundToPx().toFloat()))
                    }
                    .padding(top = 1.dp)
            } else Modifier
        )
