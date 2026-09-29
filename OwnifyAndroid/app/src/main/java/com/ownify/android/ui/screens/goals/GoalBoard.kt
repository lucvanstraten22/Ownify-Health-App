package com.ownify.android.ui.screens.goals

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.ownify.android.data.Goal
import com.ownify.android.data.Goals
import com.ownify.android.data.OwnifyActions
import com.ownify.android.data.OwnifyAppState
import com.ownify.android.data.Outcome
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The board as it is shown: the server's lists, with any change still on its way applied. */
data class Board(
    val primary: Goal?,
    val secondary: List<Goal>,
    val completed: List<Goal>,
    val slotsLeft: Int,
    val canAdd: Boolean,
    /** Cards fading out after a delete, still in their place for 200 ms. */
    val leaving: Set<String> = emptySet()
) {
    val active: Int get() = (if (primary != null) 1 else 0) + secondary.size
}

/**
 * The Doelen board's own state, as goals.js keeps it: which view is shown
 * (Actief or Behaald), and the changes a tap makes at once — a goal made
 * primary, paused or resumed, deleted — while they are on their way to the
 * server ("waiting on a round trip for a pause would feel broken").
 *
 * Nothing is worked out here: a change only moves or relabels what the
 * server already sent (the paused line and the active line are both the
 * server's), and as soon as the server has answered the board is read again,
 * so what shows is what is stored. A refusal is not quietly ignored: the
 * board is read again all the same, and the change drops away.
 */
object GoalBoard {

    /** "active" or "completed" (`[data-goal-view]`). */
    var view by mutableStateOf<String?>(null)

    /** A goal just made in the wizard, to bring into view once the board shows it. */
    var reveal by mutableStateOf<String?>(null)

    private sealed interface Edit {
        val id: String

        // Plain classes: two taps that ask the same are two changes, each removed on its own answer.
        class Promote(override val id: String) : Edit
        class Pause(override val id: String, val paused: Boolean) : Edit
        class Delete(override val id: String) : Edit {
            /** Faded out and taken off the board (goals.js removes the card after 200 ms). */
            var gone by mutableStateOf(false)
        }
    }

    private val edits = mutableStateListOf<Edit>()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    /** Whether a goal is on its way out: its detail closes and its card fades. */
    fun deleting(id: String): Boolean = edits.any { it is Edit.Delete && it.id == id }

    fun promote(context: Context, id: String) = send(context, Edit.Promote(id), "api/goals/update.php", mapOf("goal_id" to id, "action" to "primary"))

    fun pause(context: Context, id: String, paused: Boolean) =
        send(context, Edit.Pause(id, paused), "api/goals/update.php", mapOf("goal_id" to id, "action" to if (paused) "pause" else "resume"))

    fun delete(context: Context, id: String) {
        val edit = Edit.Delete(id)
        scope.launch {
            delay(200)
            edit.gone = true
        }
        send(context, edit, "api/goals/delete.php", mapOf("goal_id" to id))
    }

    /** Signed out, or another account: nothing of the last board stays. */
    fun clear() {
        edits.clear()
        view = null
        reveal = null
    }

    private fun send(context: Context, edit: Edit, path: String, fields: Map<String, String>) {
        val app = context.applicationContext
        edits.add(edit)
        scope.launch {
            val outcome = OwnifyActions.form(app, path, fields, "Deze wijziging kon niet worden opgeslagen.")
            // Refused: the optimistic change did not stick — show the truth.
            if (outcome is Outcome.Refused) OwnifyAppState.reload(app)
            edits.remove(edit)
        }
    }

    /** The board with the changes on their way applied, in the order they were made. */
    fun board(goals: Goals): Board {
        if (edits.isEmpty()) return Board(goals.primary, goals.secondary, goals.completed, goals.slotsLeft, goals.canAdd)

        var primary = goals.primary
        val secondary = goals.secondary.toMutableList()
        val leaving = mutableSetOf<String>()

        for (edit in edits.toList()) {
            when (edit) {
                is Edit.Delete -> {
                    if (!edit.gone) {
                        leaving += edit.id
                    } else if (primary?.id == edit.id) {
                        // The board is never left without a primary goal.
                        primary = secondary.removeFirstOrNull()?.asPrimary()
                    } else {
                        secondary.removeAll { it.id == edit.id }
                    }
                }

                is Edit.Promote -> {
                    val at = secondary.indexOfFirst { it.id == edit.id }
                    if (at >= 0) {
                        // Always a swap: the outgoing primary takes the promoted card's place.
                        val promoted = secondary[at]
                        val outgoing = primary
                        if (outgoing != null) secondary[at] = outgoing.asSecondary() else secondary.removeAt(at)
                        primary = promoted.asPrimary()
                    }
                }

                is Edit.Pause -> {
                    if (primary?.id == edit.id) primary = primary.paused(edit.paused)
                    val at = secondary.indexOfFirst { it.id == edit.id }
                    if (at >= 0) secondary[at] = secondary[at].paused(edit.paused)
                }
            }
        }

        val active = (if (primary != null) 1 else 0) + secondary.size - leaving.size
        val left = maxOf(0, goals.limits - active)
        return Board(primary, secondary, goals.completed, left, left > 0, leaving)
    }

    /** One goal, as its detail page shows it, with the changes on their way applied. */
    fun goal(goal: Goal, goals: Goals): Goal {
        if (edits.isEmpty()) return goal
        val board = board(goals)
        return when {
            board.primary?.id == goal.id -> board.primary
            else -> board.secondary.firstOrNull { it.id == goal.id } ?: goal
        }
    }

    private fun Goal.asPrimary() = copy(priority = "primary", isPrimary = true)

    private fun Goal.asSecondary() = copy(priority = "secondary", isPrimary = false)

    private fun Goal.paused(paused: Boolean) =
        copy(isPaused = paused, status = if (paused) "paused" else "active", deadlineLine = if (paused) linePaused else lineActive)
}
