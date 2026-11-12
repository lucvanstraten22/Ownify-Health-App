package com.ownify.android.ui.screens.settings

import com.ownify.android.i18n.tr
import com.ownify.android.i18n.trf
import com.ownify.android.i18n.trn
import com.ownify.android.i18n.OwnifyFormat
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
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
import com.ownify.android.connection.OwnifySyncRunner
import com.ownify.android.connection.OwnifyUpdates
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

/** The Health Connect read permissions the sync can use: one per record type it sends. */
val HealthPermissions: Set<String> = IngestPayload.permissions()

private const val HEALTH_CONNECT_PACKAGE = "com.google.android.apps.healthdata"
private const val PLAY_STORE_PACKAGE = "com.android.vending"

/** 43.0: an hourly sync this long ago is late — Android is likely holding it back. */
private val LATE = java.time.Duration.ofHours(3)

/** What Health Connect on this phone allows, read when the screen opens and again on every return to it. */
@Stable
class PhoneHealth {
    var sdk by mutableIntStateOf(HealthConnectClient.SDK_UNAVAILABLE)
    var granted by mutableIntStateOf(0)
    var background by mutableStateOf<BackgroundRead?>(null)
    var known by mutableStateOf(false)

    /** What this phone's Health Connect can grant (43.0: skin temperature only where it has the feature). */
    var permissions by mutableStateOf(HealthPermissions)

    val total: Int get() = permissions.size
    val available: Boolean get() = sdk == HealthConnectClient.SDK_AVAILABLE

    /** 43.0: whether Android's battery optimisation applies to Ownify — it may then hold the hourly sync back. Null: unknown. */
    var batteryRestricted by mutableStateOf<Boolean?>(null)

    /** 43.0: whether the Play Store is on this phone — Health Connect is installed and updated from there. */
    var playStore by mutableStateOf(true)

    /** 43.0: Android's own version — Health Connect is part of Android from 14 on. */
    var androidVersion by mutableIntStateOf(Build.VERSION.SDK_INT)

    suspend fun read(context: Context) {
        val app = context.applicationContext
        val status = withContext(Dispatchers.IO) { runCatching { HealthConnectClient.getSdkStatus(app) }.getOrDefault(HealthConnectClient.SDK_UNAVAILABLE) }
        sdk = status
        batteryRestricted = runCatching {
            !(app.getSystemService(Context.POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(app.packageName)
        }.getOrNull()
        playStore = runCatching { app.packageManager.getPackageInfo(PLAY_STORE_PACKAGE, 0); true }.getOrDefault(false)
        if (status == HealthConnectClient.SDK_AVAILABLE) {
            val client = runCatching { HealthConnectClient.getOrCreate(app) }.getOrNull()
            if (client != null) permissions = IngestPayload.permissions(IngestPayload.availableTypes(client))
            val have = runCatching { client!!.permissionController.getGrantedPermissions() }.getOrDefault(emptySet())
            granted = permissions.count { it in have }
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
            // 43.0: the first import of a newly connected phone, while it is under way.
            val historyFrom = record.historyFrom
            if (!record.historyDone && historyFrom != null) {
                val total = OwnifySyncRunner.HISTORY.toDays().toInt()
                val done = java.time.Duration.between(historyFrom, Instant.now()).toDays().toInt().coerceIn(0, total)
                PhoneRow(tr("Eerste import"), trf("%d van %d dagen", done, total))
            }
            if (active && health.batteryRestricted != null) {
                PhoneRow(
                    tr("Batterijbeheer"),
                    if (health.batteryRestricted == true) tr("Geoptimaliseerd") else tr("Onbeperkt"),
                    empty = health.batteryRestricted == true
                )
            }
            PhoneRow(tr("App-versie"), OwnifyUpdates.installed(context).ifEmpty { "—" })
            OwnifyUpdates.available?.let { update -> PhoneRow(tr("Nieuwe versie"), update.version) }
        }

        // 43.0: why, in a line, when something stands in the way.
        val late = active && record.lastSuccessAt?.let { java.time.Duration.between(it, Instant.now()) > LATE } == true
        val notes = buildList {
            healthConnectNote(health)?.let(::add)
            if (late) add(tr("De laatste synchronisatie is meer dan drie uur geleden. Android kan synchroniseren op de achtergrond uitstellen om de batterij te sparen."))
            if (active && health.batteryRestricted == true && (late || health.background == BackgroundRead.GRANTED)) {
                add(tr("Zet de batterijbeperking voor Ownify uit, zodat het elk uur kan synchroniseren terwijl de app dicht is."))
                manufacturerNote()?.let(::add)
            }
        }
        for (note in notes) {
            T(note, OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextMuted), Modifier.fillMaxWidth().padding(top = Ownify.Space3))
        }

        // What can be asked for, only when it is missing.
        val needsInstall = health.known && canInstall(health)
        val needsRead = health.available && health.granted < health.total
        val needsBackground = health.available && health.granted > 0 && health.background == BackgroundRead.NOT_GRANTED
        val needsBattery = active && health.batteryRestricted == true && (late || health.background == BackgroundRead.GRANTED)
        val update = OwnifyUpdates.available
        if (needsInstall || needsRead || needsBackground || needsBattery || update != null) {
            Column(Modifier.fillMaxWidth().padding(top = Ownify.Space4), verticalArrangement = Arrangement.spacedBy(Ownify.Space2)) {
                if (needsInstall) {
                    Btn(
                        if (health.sdk == HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED) tr("Health Connect bijwerken") else tr("Health Connect installeren"),
                        onClick = { openStore(context) },
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                if (needsRead) Btn(tr("Toegang geven"), onClick = { askRead.launch(health.permissions) }, modifier = Modifier.fillMaxWidth())
                if (needsBackground) {
                    Btn(
                        tr("Op de achtergrond toestaan"),
                        onClick = { askBackground.launch(setOf(OwnifyBackgroundSync.BACKGROUND_PERMISSION)) },
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                if (needsBattery) {
                    Btn(tr("Batterijbeperking uitzetten"), onClick = { openBatterySettings(context) }, modifier = Modifier.fillMaxWidth())
                }
                if (update != null) {
                    // To the APK on GitHub, in the browser: nothing is downloaded or installed by the app itself.
                    Btn(trf("Ownify %s downloaden", update.version), onClick = { openLink(context, update.apk) }, modifier = Modifier.fillMaxWidth())
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
            OwnifyType.style(Ownify.FsSubhead, color = if (OwnifySync.state is OwnifySyncState.Failed) Ownify.Attention else Ownify.TextMuted),
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
fun PhoneRow(label: String, value: String, first: Boolean = false, empty: Boolean = false, list: Boolean = false, last: Boolean = false) {
    Column(Modifier.fillMaxWidth()) {
        if (!first) Box(Modifier.fillMaxWidth().height(1.dp).background(Ownify.GlassHairline))
        Row(
            Modifier
                .fillMaxWidth()
                // The last row of a `.settings-rows` card ends with its words (C40, 50.0): the card's own padding is all below it.
                .cssPadding(top = if (first) 0.dp else Ownify.Space3, bottom = if (last) 0.dp else Ownify.Space3, above = if (first) 0.dp else 1.dp)
                .semantics(mergeDescendants = true) { },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Ownify.Space3)
        ) {
            T(
                label,
                if (list) OwnifyType.style(Ownify.FsBody, FontWeight.Normal) else OwnifyType.style(Ownify.FsSubhead, color = Ownify.TextSecondary),
                Modifier.weight(1f)
            )
            T(
                value,
                OwnifyType.style(
                    Ownify.FsSubhead,
                    FontWeight.Normal,
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

/** Health Connect in the Play Store — to install or update it (also the setup's Gegevens step). */
internal fun openHealthConnectStore(context: Context) = openStore(context)

/**
 * 43.0 — whether Health Connect can be installed or updated from here: an
 * update always can; installing only on Android 13 and older, where it is
 * a separate app — from 14 on it is part of Android.
 */
internal fun canInstall(health: PhoneHealth): Boolean = !health.available &&
    (health.sdk == HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED ||
        health.androidVersion < Build.VERSION_CODES.UPSIDE_DOWN_CAKE)

/** 43.0: why Health Connect is not there, and what to do — or null when it is. */
internal fun healthConnectNote(health: PhoneHealth): String? = when {
    !health.known || health.available -> null
    !health.playStore && canInstall(health) ->
        tr("Health Connect komt uit de Play Store, en die staat niet op deze telefoon. Zonder Health Connect kan Ownify hier niets lezen.")
    health.sdk == HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED ->
        if (health.androidVersion >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            tr("Ownify heeft een nieuwere Health Connect nodig. Op deze telefoon hoort Health Connect bij Android: werk het bij via de Play Store of de systeemupdates van Google Play.")
        } else {
            tr("Ownify heeft een nieuwere Health Connect nodig. Werk de app Health Connect bij in de Play Store.")
        }
    health.androidVersion < Build.VERSION_CODES.UPSIDE_DOWN_CAKE ->
        tr("Op Android 13 en ouder is Health Connect een aparte, gratis app. Installeer die uit de Play Store en geef Ownify daarna toegang.")
    else ->
        tr("Health Connect hoort bij Android, maar is hier niet te gebruiken — bijvoorbeeld in een werkprofiel. Ownify kan op deze telefoon niets lezen.")
}

/** 43.0: the phone maker's own extra switch for apps in the background, where it has one. */
internal fun manufacturerNote(maker: String = Build.MANUFACTURER.orEmpty()): String? = when (maker.lowercase()) {
    "xiaomi", "redmi", "poco" -> tr("Op Xiaomi-telefoons: zet ook Autostart aan voor Ownify (Instellingen › Apps › Ownify).")
    "huawei", "honor" -> tr("Op Huawei- en Honor-telefoons: kies bij App-start voor Ownify ‘Handmatig beheren’ en sta uitvoeren op de achtergrond toe.")
    "samsung" -> tr("Op Samsung-telefoons: zet Ownify niet bij de apps in slaapstand (Instellingen › Batterij › Achtergrondgebruik).")
    "oneplus", "oppo", "realme", "vivo" -> tr("Op deze telefoon: sta Ownify ook toe op de achtergrond te draaien bij de batterij-instellingen van de app.")
    else -> null
}

/**
 * Asks Android to let Ownify run without battery optimisation — its own
 * one-tap dialog — or, where that is not offered, opens the list where it
 * is set, or Ownify's app settings.
 */
internal fun openBatterySettings(context: Context) {
    val pkg = context.packageName
    val tries = listOf(
        Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, "package:$pkg".toUri()),
        Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS),
        Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, "package:$pkg".toUri())
    )
    for (intent in tries) {
        if (runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess) return
    }
}

/** A web link in the browser. */
internal fun openLink(context: Context, url: String) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, url.toUri()).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}

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
