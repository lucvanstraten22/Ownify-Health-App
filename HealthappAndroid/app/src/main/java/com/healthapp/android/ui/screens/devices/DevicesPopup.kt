package com.healthapp.android.ui.screens.devices

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.healthapp.android.data.AppData
import com.healthapp.android.ui.app.Detail
import com.healthapp.android.ui.app.LocalShell
import com.healthapp.android.ui.app.Overlay
import com.healthapp.android.ui.app.OverlayFrame
import com.healthapp.android.ui.app.Placement
import com.healthapp.android.ui.app.panelGlass
import com.healthapp.android.ui.design.Btn
import com.healthapp.android.ui.design.IconTile
import com.healthapp.android.ui.design.JStyle
import com.healthapp.android.ui.design.JoluIcons
import com.healthapp.android.ui.design.LocalScreen
import com.healthapp.android.ui.design.T
import com.healthapp.android.ui.theme.Jolu
import com.healthapp.android.ui.theme.JoluType

/** One line of the popup: a paired phone, or a source without phones. */
private data class DeviceLine(val icon: String, val connected: Boolean, val state: String, val name: String, val source: String?)

/**
 * The quick look behind the header's devices button
 * (components/devices-popup.php): what is linked and whether it is working,
 * hanging from the button, and the way to Instellingen › Apparaten &
 * Gezondheid to change anything.
 */
@Composable
fun DevicesPopup(overlay: Overlay, data: AppData) {
    val shell = LocalShell.current
    val screen = LocalScreen.current
    val top = WindowInsets.safeDrawing.asPaddingValues().calculateTopPadding()
    val labels = data.settings.integrationLabels

    // Linked is connected, or connected with a last sync that failed; the rest is not shown.
    val lines = data.settings.integrations
        .filter { it.status == "connected" || it.status == "error" }
        .flatMap { source ->
            val connected = source.status == "connected"
            val state = if (connected) labels["connected"].orEmpty() else labels["error"].orEmpty()
            if (source.devices.isNotEmpty()) source.devices.map { DeviceLine(source.icon, connected, state, it.label, source.label) }
            else listOf(DeviceLine(source.icon, connected, state, source.label, null))
        }

    OverlayFrame(overlay, title = data.header.devicesLabel, placement = Placement.UnderHeader) { panelModifier ->
        // .devices__frame — the header's column, just under its row.
        Box(
            Modifier
                .fillMaxWidth()
                .padding(top = top + Jolu.Space3 + Jolu.HeaderButton + Jolu.Space2),
            contentAlignment = Alignment.TopCenter
        ) {
            Box(Modifier.width(screen.shell)) {
                Column(
                    panelModifier
                        .widthIn(max = 296.dp)
                        .panelGlass()
                        .padding(Jolu.Space4)
                ) {
                    if (lines.isEmpty()) {
                        T(
                            data.header.devicesEmpty,
                            JoluType.style(Jolu.FsSmall, color = Jolu.TextSecondary),
                            Modifier.fillMaxWidth().padding(top = Jolu.Space1, bottom = Jolu.Space4),
                            align = TextAlign.Center
                        )
                    } else {
                        Column(Modifier.padding(bottom = Jolu.Space4), verticalArrangement = Arrangement.spacedBy(Jolu.Space3)) {
                            lines.forEach { DeviceRow(it) }
                        }
                    }
                    Btn(
                        data.header.devicesAdd,
                        onClick = {
                            // Instellingen first, then its devices screen on top of it.
                            shell.close(overlay)
                            shell.goTo("settings")
                            shell.openDetail(Detail.SettingsPage("devices"))
                        },
                        icon = JoluIcons.plus,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            }
        }
    }
}

@Composable
private fun DeviceRow(line: DeviceLine) {
    Row(
        Modifier.fillMaxWidth().semantics(mergeDescendants = true) { },
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Jolu.Space3)
    ) {
        JoluIcons.named(line.icon)?.let {
            IconTile(it, size = 32.dp, radius = 11.dp, iconSize = 17.dp, color = if (line.connected) Jolu.Health else Jolu.TextSecondary)
        }
        Column(Modifier.weight(1f)) {
            T(line.name, JoluType.style(Jolu.FsLabel, FontWeight.SemiBold), maxLines = 1, ellipsis = true)
            if (line.source != null) T(line.source, JStyle.Tiny, Modifier.padding(top = 2.dp), maxLines = 1, ellipsis = true)
        }
        StatusDot(line.state, line.connected)
    }
}

/** `.devices__status` / `.integration__status`: a 6 dot and a word; green with its halo when connected. */
@Composable
fun StatusDot(text: String, connected: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(Jolu.Space2)) {
        Box(
            Modifier
                .size(6.dp)
                .drawBehind {
                    if (connected) {
                        drawCircle(Jolu.Health.copy(alpha = 0.18f), radius = size.width / 2f + 3.dp.toPx())
                        drawCircle(Jolu.Health)
                    } else {
                        drawCircle(Jolu.TextFaint)
                    }
                }
        )
        T(
            text,
            JoluType.style(Jolu.FsTiny, color = if (connected) Jolu.mix(Jolu.Health, 0.34f, androidx.compose.ui.graphics.Color.White) else Jolu.TextMuted),
            maxLines = 1
        )
    }
}
