package com.ownify.android.ui.screens

import com.ownify.android.i18n.tr
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import com.ownify.android.connection.OwnifyConnection
import com.ownify.android.connection.OwnifyState
import com.ownify.android.data.TrainingView
import com.ownify.android.ui.app.Detail
import com.ownify.android.ui.app.LocalShell
import com.ownify.android.ui.app.Overlay
import com.ownify.android.ui.app.ShellScreens
import com.ownify.android.ui.app.ShellState
import com.ownify.android.ui.app.SignedOutScreens
import com.ownify.android.ui.design.Ground
import com.ownify.android.ui.design.GroundPlacement
import com.ownify.android.ui.design.LocalBackdrop
import com.ownify.android.ui.design.LocalGround
import com.ownify.android.ui.design.ground
import com.ownify.android.ui.design.recordBackdrop
import com.ownify.android.ui.design.rememberBackdrop
import com.ownify.android.ui.screens.account.AccountPanel
import com.ownify.android.ui.screens.account.SignedOutPanel
import com.ownify.android.ui.screens.assistant.AssistantSheet
import com.ownify.android.ui.screens.community.ChallengeCodeSheet
import com.ownify.android.ui.screens.community.ChallengeCreateSheet
import com.ownify.android.ui.screens.community.ChallengeDetail
import com.ownify.android.ui.screens.community.ChallengeInviteSheet
import com.ownify.android.ui.screens.community.ChallengeLeaveSheet
import com.ownify.android.ui.screens.community.ChallengeReviewSheet
import com.ownify.android.ui.screens.community.ChallengesDetail
import com.ownify.android.ui.screens.community.CommunityPage
import com.ownify.android.ui.screens.community.LeaderboardDetail
import com.ownify.android.ui.screens.devices.DevicesPopup
import com.ownify.android.ui.screens.goals.GoalDetail
import com.ownify.android.ui.screens.goals.GoalBoard
import com.ownify.android.ui.screens.goals.GoalWizard
import com.ownify.android.ui.screens.goals.GoalsPage
import com.ownify.android.ui.screens.health.HealthDetail
import com.ownify.android.ui.screens.health.AreaChartDetail
import com.ownify.android.ui.screens.health.TrainingSessionDetail
import com.ownify.android.ui.screens.health.HealthPage
import com.ownify.android.ui.screens.overview.OverviewPage
import com.ownify.android.ui.screens.overview.ScoreCompassDetail
import com.ownify.android.ui.screens.settings.DeleteConfirm
import com.ownify.android.ui.screens.settings.DeviceNameEditor
import com.ownify.android.ui.screens.settings.FieldEditor
import com.ownify.android.ui.screens.settings.PairingPanel
import com.ownify.android.ui.screens.settings.PasswordEditor
import com.ownify.android.ui.screens.settings.SettingsDetail
import com.ownify.android.ui.screens.settings.SettingsPage
import com.ownify.android.ui.screens.setup.SetupScreen
import com.ownify.android.ui.screens.welcome.PairedStatus
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier

/**
 * What the app shows where: every page, detail, sheet and panel of the
 * website, by the name the website gives it.
 */
object OwnifyScreens {

    val shell = ShellScreens(
        page = { id, data, scroll ->
            when (id) {
                "overview" -> OverviewPage(data, scroll)
                "health" -> HealthPage(data, scroll)
                "goals" -> GoalsPage(data, scroll)
                "community" -> CommunityPage(data, scroll)
                "settings" -> SettingsPage(data, scroll)
            }
        },
        detail = { detail, data, scroll ->
            when (detail) {
                Detail.ScoreCompass -> ScoreCompassDetail(data, scroll)
                is Detail.HealthArea -> data.health.area(detail.id)?.let { HealthDetail(data, it, scroll) }
                is Detail.GoalPage -> data.goals.goal(detail.id)?.let { GoalDetail(data, it, scroll) }
                is Detail.SettingsPage -> data.settings.page(detail.id)?.let { SettingsDetail(data, it, scroll) }
                is Detail.AreaChart -> data.health.area(detail.area)?.view?.chart(detail.id)?.let { AreaChartDetail(it, detail.area, scroll) }
                is Detail.TrainingSession -> (data.health.area("training")?.view as? TrainingView)?.let { view ->
                    view.session(detail.id)?.let { TrainingSessionDetail(it, view.heart, scroll) }
                }
                Detail.Leaderboard -> LeaderboardDetail(data)
                Detail.Challenges -> ChallengesDetail(data, scroll)
                is Detail.Challenge -> data.challenges.view(detail.id)?.let { ChallengeDetail(data, it, detail.overList, scroll) }
            }
        },
        assistant = { data -> AssistantSheet(data.ai) },
        overlay = { overlay, data ->
            when (overlay) {
                Overlay.Devices -> DevicesPopup(overlay, data)
                is Overlay.Account -> AccountPanel(overlay, data)
                Overlay.Wizard -> GoalWizard(overlay, data)
                Overlay.DeleteAccount -> DeleteConfirm(overlay, data)
                is Overlay.EditField -> FieldEditor(overlay, data)
                is Overlay.RenameDevice -> DeviceNameEditor(overlay, data)
                Overlay.ChangePassword -> PasswordEditor(Overlay.ChangePassword)
                is Overlay.Pairing -> PairingPanel(overlay, data)
                is Overlay.ChallengeCreate -> ChallengeCreateSheet(overlay, data)
                Overlay.ChallengeCode -> ChallengeCodeSheet(Overlay.ChallengeCode, data)
                is Overlay.ChallengeReview -> ChallengeReviewSheet(overlay, data)
                is Overlay.ChallengeInvite -> ChallengeInviteSheet(overlay, data)
                is Overlay.ChallengeLeave -> ChallengeLeaveSheet(overlay, data)
            }
        },
        setup = { data -> SetupScreen(data) }
    )

    val signedOut = SignedOutScreens { paired -> WelcomeRoute(paired) }
}

/**
 * The opening screen with its own panel host: the account panel opens on
 * the flow its button names, and opens by itself when there is something to
 * say — a session that ran out, a sign-out the server did not hear.
 */
@Composable
private fun WelcomeRoute(paired: Boolean) {
    val scope = rememberCoroutineScope()
    val host = remember { ShellState(listOf("welcome"), "welcome", scope) }
    val (backdrop, layer) = rememberBackdrop()
    val ground = remember { GroundPlacement(Ground.App) }
    val state = OwnifyConnection.state
    val notice = (state as? OwnifyState.NotConnected)?.takeIf { it.message != null && it.message != OwnifyConnection.LOGGED_OUT_MESSAGE }

    // Nothing of a signed-out account's goal board stays behind.
    LaunchedEffect(Unit) { GoalBoard.clear() }

    // The panel opens by itself when there is something to say (auth_flash on the website).
    LaunchedEffect(notice) {
        if (notice != null && !paired && host.overlays.none { it is Overlay.Account }) host.open(Overlay.Account("login"))
    }

    androidx.activity.compose.BackHandler(enabled = host.overlayOpen) { host.back() }

    CompositionLocalProvider(LocalShell provides host) {
        Box(Modifier.fillMaxSize()) {
            Box(Modifier.fillMaxSize().recordBackdrop(backdrop, layer).ground(ground)) {
                CompositionLocalProvider(LocalGround provides ground) {
                    WelcomeScreen(
                        appName = SignedOutCopy.APP_NAME,
                        subtitle = SignedOutCopy.SUBTITLE,
                        login = SignedOutCopy.LOGIN,
                        register = SignedOutCopy.REGISTER,
                        onLogin = { host.open(Overlay.Account("login")) },
                        onRegister = { host.open(Overlay.Account("register")) },
                        below = if (paired) ({ PairedStatus() }) else null
                    )
                }
            }
            CompositionLocalProvider(LocalBackdrop provides backdrop) {
                for (overlay in host.overlays.toList()) {
                    if (overlay is Overlay.Account) SignedOutPanel(overlay, notice?.message, notice?.link)
                }
            }
        }
    }
}

/**
 * The opening screen's words. Signed out there is no account to read them
 * for, so they are the website's own (config/dashboard.php `app` and
 * `welcome`), copied here.
 */
object SignedOutCopy {
    const val APP_NAME = "Ownify"
    /* In the app's language (OwnifyLanguage): the phone's, or the one chosen last. */
    val SUBTITLE: String get() = tr("Je slaap, voeding en training in één helder overzicht.")
    val LOGIN: String get() = tr("Inloggen")
    val REGISTER: String get() = tr("Registreren")
}
