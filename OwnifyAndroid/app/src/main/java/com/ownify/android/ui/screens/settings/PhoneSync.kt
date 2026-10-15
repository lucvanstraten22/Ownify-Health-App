package com.ownify.android.ui.screens.settings

import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.trn
import com.ownify.android.i18n.OwnifyFormat
import android.content.Context
import android.content.Intent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.permission.HealthPermission
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.ownify.android.connection.BackgroundRead
import com.ownify.android.connection.IngestPayload
import com.ownify.android.connection.OwnifyBackgroundSync
import com.ownify.android.connection.OwnifySync
import com.ownify.android.connection.OwnifySyncOutcomeKind
import com.ownify.android.connection.OwnifySyncState
import com.ownify.android.ui.design.Btn
import com.ownify.android.ui.design.JStyle
import com.ownify.android.ui.design.T
import com.ownify.android.ui.design.cssPadding
import com.ownify.android.ui.theme.Ownify
import com.ownify.android.ui.theme.OwnifyType
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/*
 * This phone's own side of Health Connect. The website can only list the
 * phone; the phone can also say whether Health Connect is there, what Ownify
 * may read, whether it may read while Ownify is closed, and how its last sync
 * went — and ask for that access. Android-only (docs/PARITY.md).
 */

/** The Health Connect read permissions the sync needs: one per record type it sends. */
val HealthPermissions: Set<String> = IngestPayload.TYPES.map { HealthPermission.getReadPermission(it) }.toSet()

private const val HEALTH_CONNECT_PACKAGE = "com.google.android.apps.healthdata"

/** What Health Connect on this phone allows, read when the screen opens and again on every return to it. */
@Stable
class PhoneHealth {
    var sdk by mutableIntStateOf(HealthConnectClient.SDK_UNAVAILABLE)
    var granted by mutableIntStateOf(0)
    var background by mutableStateOf<BackgroundRead?>(null)
    var known by mutableStateOf(false)

    val total: Int get() = IngestPayload.TYPES.size
    val available: Boolean get() = sdk == HealthConnectClient.SDK_AVAILABLE

    suspend fun read(context: Context) {
        val app = context.applicationContext
        val status = withContext(Dispatchers.IO) { runCatching { HealthConnectClient.getSdkStatus(app) }.getOrDefault(HealthConnectClient.SDK_UNAVAILABLE) }
        sdk = status
        if (status == HealthConnectClient.SDK_AVAILABLE) {
            val permissions = runCatching { HealthConnectClient.getOrCreate(app).permissionController.getGrantedPermissions() }.getOrDefault(emptySet())
            granted = HealthPermissions.count { it in permissions }
            background = runCatching { OwnifyBackgroundSync.backgroundRead(app) }.getOrNull()
        } else {
            granted = 0
            background = null
        }
        known = true
    }
}

/** A [PhoneHealth] kept current: read now, and again whenever Ownify comes back to the front. */
@Composable
fun rememberPhoneHealth(): PhoneHealth {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val health = remember { PhoneHealth() }
    var reads by remember { mutableIntStateOf(0) }

    LaunchedEffect(reads) { health.read(context) }
    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event -> if (event == Lifecycle.Event.ON_RESUME) reads++ }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    return health
}

/**
 * tr("Deze telefoon"): Health Connect's state on this phone as metric rows, the
 * last sync, and the buttons that ask for access — every read type at once,
 * then reading while Ownify is closed.
 */
@Composable
fun PhoneSection(health: PhoneHealth, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val record = OwnifySync.record
    val active by remember { OwnifyBackgroundSync.active(context) }.collectAsState(initial = false)

    val scope = rememberCoroutineScope()
    val askRead = rememberLauncherForActivityResult(PermissionController.createRequestPermissionResultContract()) { granted ->
        // Something can be read now: the automatic sync picks it up (as MainActivity always did).
        if (granted.isNotEmpty()) OwnifyBackgroundSync.healthAccessGranted(context)
        scope.launch { health.read(context) }
    }
    val askBackground = rememberLauncherForActivityResult(PermissionController.createRequestPermissionResultContract()) { granted ->
        if (OwnifyBackgroundSync.BACKGROUND_PERMISSION in granted) OwnifyBackgroundSync.healthAccessGranted(context)
        scope.launch { health.read(context) }
    }

    LaunchedEffect(Unit) { OwnifySync.refresh(context) }

    Column(modifier.fillMaxWidth()) {
        Caption(tr("Deze telefoon"))
        Column(Modifier.fillMaxWidth().padding(top = Ownify.Space2)) {
            PhoneRow(
                "Health Connect",
                when {
                    !health.known -> "—"
                    health.available -> tr("Beschikbaar")
                    health.sdk == HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> tr("Moet worden bijgewerkt")
                    else -> tr("Niet beschikbaar")
                },
                first = true
            )
            if (health.available) {
                PhoneRow(
                    tr("Toegang"),
                    when (health.granted) {
                        health.total -> tr("Alle gegevens")
                        0 -> tr("Geen toegang")
                        else -> trf("%d van %d soorten", health.granted, health.total)
                    },
                    empty = health.granted == 0
                )
                PhoneRow(
                    tr("Op de achtergrond"),
                    when (health.background) {
                        BackgroundRead.GRANTED -> tr("Toegestaan")
                        BackgroundRead.NOT_GRANTED -> tr("Niet toegestaan")
                        BackgroundRead.NOT_SUPPORTED -> tr("Niet ondersteund")
                        else -> "—"
                    },
                    empty = health.background != BackgroundRead.GRANTED
                )
            }
            PhoneRow(
                tr("Automatisch"),
                if (active) intervalText() else tr("Uit"),
                empty = !active
            )
            PhoneRow(
                tr("Laatste synchronisatie"),
                record.lastSuccessAt?.let(::moment) ?: tr("Nog nooit"),
                empty = record.lastSuccessAt == null
            )
            record.lastOutcome?.let { PhoneRow(tr("Status"), outcomeText(it)) }
        }

        // What can be asked for, only when it is missing.
        val needsInstall = health.known && !health.available
        val needsRead = health.available && health.granted < health.total
        val needsBackground = health.available && health.granted > 0 && health.background == BackgroundRead.NOT_GRANTED
        if (needsInstall || needsRead || needsBackground) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                if (needsInstall) {
                    Btn(
                        if (health.sdk == HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED) tr("Health Connect bijwerken") else tr("Health Connect installeren"),
                        onClick = { openStore(context) },
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                if (needsRead) Btn(tr("Toegang geven"), onClick = { askRead.launch(HealthPermissions) }, modifier = Modifier.fillMaxWidth())
                if (needsBackground) {
                    Btn(
                        tr("Op de achtergrond toestaan"),
                        onClick = { askBackground.launch(setOf(OwnifyBackgroundSync.BACKGROUND_PERMISSION)) },
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            }
        }
    }
}

/** What the last sync on screen did, in a line or two — or why it did not. */
@Composable
fun SyncResult(modifier: Modifier = Modifier) {
    val line: String? = when (val state = OwnifySync.state) {
        OwnifySyncState.Idle -> null
        OwnifySyncState.Syncing -> tr("Synchroniseren…")
        is OwnifySyncState.Failed -> state.message
        is OwnifySyncState.Synced -> {
            val result = state.summary.result
            val points = result.points.joinToString("") { "\n+${it.points} — ${it.label}" }
            trf("Gesynchroniseerd: %s, %d al bekend.", trn(result.written, "%d nieuwe meting", "%d nieuwe metingen"), result.skipped) + points
        }
    }
    if (line != null) {
        T(
            line,
            OwnifyType.style(Ownify.FsTiny, color = if (OwnifySync.state is OwnifySyncState.Failed) Ownify.Attention else Ownify.TextMuted),
            modifier.fillMaxWidth().padding(top = Ownify.Space3).semantics { liveRegion = LiveRegionMode.Polite }
        )
    }
}

/** `.integration__caption`. */
@Composable
fun Caption(text: String, modifier: Modifier = Modifier) {
    T(text, JStyle.Caption, modifier.fillMaxWidth(), uppercase = true)
}

/**
 * A `.metric-row` as Settings shows them: the name, the value, a hairline
 * between. The value in the secondary ink at 500, as every value in Settings;
 * in a plain list of values ([list], `.settings-rows`) the name is a row's
 * label, as on every other settings row.
 */
@Composable
fun PhoneRow(label: String, value: String, first: Boolean = false, empty: Boolean = false, list: Boolean = false) {
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                .cssPadding(top = if (first) 0.dp else Ownify.Space3, bottom = Ownify.Space3, above = if (first) 0.dp else 1.dp)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            T(
                label,
                if (list) OwnifyType.style(Ownify.FsLabel, FontWeight.Medium) else OwnifyType.style(Ownify.FsSmall, color = Ownify.TextSecondary),
                Modifier.weight(1f)
            )
            T(
                value,
                OwnifyType.style(
                    Ownify.FsSmall,
                    FontWeight.Medium,
                    if (empty) Ownify.TextMuted else Ownify.TextSecondary,
                    tabular = true
                ),
                align = TextAlign.End
            )
        }
    }
}

private fun intervalText(): String {
    val hours = OwnifyBackgroundSync.INTERVAL.toHours()
    return if (hours == 1L) tr("Elk uur") else trf("Elke %d uur", hours)
}

/**
 * A moment the way the website writes a sync (settings_sync_label()):
 * "Vandaag, 14:35", "Gisteren, 09:12", "3-9-2026, 18:02" — in English
 * "Today, 14:35", "Yesterday, 09:12", "9/3/2026, 18:02".
 */
internal fun moment(instant: Instant, today: LocalDate = LocalDate.now()): String {
    val at = instant.atZone(ZoneId.systemDefault())
    val time = at.format(DateTimeFormatter.ofPattern("HH:mm"))
    val day = at.toLocalDate()
    return when (day) {
        today -> tr("Vandaag") + ", " + time
        today.minusDays(1) -> tr("Gisteren") + ", " + time
        else -> OwnifyFormat.date(day, "numeric") + ", " + time
    }
}

/** How the last sync went, in the app's words (OwnifySyncOutcomeKind keeps its own, for the tests). */
internal fun outcomeText(kind: OwnifySyncOutcomeKind): String = when (kind) {
    OwnifySyncOutcomeKind.SYNCED -> tr("Gesynchroniseerd")
    OwnifySyncOutcomeKind.OFFLINE -> tr("Ownify was niet bereikbaar — wordt vanzelf opnieuw geprobeerd")
    OwnifySyncOutcomeKind.SERVER_TROUBLE -> tr("Ownify had een probleem — wordt vanzelf opnieuw geprobeerd")
    OwnifySyncOutcomeKind.READ_FAILED -> tr("Health Connect kon niet worden gelezen — wordt opnieuw geprobeerd")
    OwnifySyncOutcomeKind.NEEDS_HEALTH_ACCESS -> tr("Geef toegang tot Health Connect om automatisch te synchroniseren")
    OwnifySyncOutcomeKind.NEEDS_BACKGROUND_ACCESS -> tr("Synchroniseert terwijl Ownify open is; sta de achtergrond toe voor daarna")
    OwnifySyncOutcomeKind.NO_HEALTH_CONNECT -> tr("Health Connect is niet beschikbaar op deze telefoon")
    OwnifySyncOutcomeKind.NOT_CONNECTED -> tr("Niet verbonden met Ownify")
    OwnifySyncOutcomeKind.EXPIRED -> tr("De koppeling met Ownify is verlopen")
}

/** Health Connect's own page in the Play Store: to install it, or bring it up to date. */
/** Health Connect in the Play Store — to install or update it (also the setup's Gegevens step). */
internal fun openHealthConnectStore(context: Context) = openStore(context)

private fun openStore(context: Context) {
    val uri = "market://details?id=$HEALTH_CONNECT_PACKAGE&url=healthconnect%3A%2F%2Fonboarding".toUri()
    val intent = Intent(Intent.ACTION_VIEW, uri).apply {
        setPackage("com.android.vending")
        putExtra("overlay", true)
        putExtra("callerId", context.packageName)
        addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }
    runCatching { context.startActivity(intent) }.onFailure {
        runCatching {
            context.startActivity(
                Intent(Intent.ACTION_VIEW, "https://play.google.com/store/apps/details?id=$HEALTH_CONNECT_PACKAGE".toUri())
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
        }
    }
}
