package com.healthapp.android.jolu

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import java.text.NumberFormat

private const val NO_DATA = "No data"

/** Room for a code typed with spaces or a dash; anything longer is cut off. */
private const val MAX_CODE_INPUT = 16

/**
 * "Connect JoLu account": pairs this phone with a code from the JoLu website,
 * then shows the account's profile and the daily targets the server worked
 * out for it.
 *
 * It shows only what the server sent. Nothing is calculated here and nothing
 * is filled in: a value JoLu does not have reads "No data".
 */
@Composable
fun JoluConnectionSection(modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val focusManager = LocalFocusManager.current
    val state = JoluConnection.state

    var code by remember { mutableStateOf("") }

    LaunchedEffect(Unit) {
        JoluConnection.start(context)
    }

    // The code is only kept while the pairing form is on screen.
    LaunchedEffect(state) {
        if (state !is JoluState.NotConnected && state !is JoluState.Pairing) {
            code = ""
        }
    }

    fun submit() {
        focusManager.clearFocus()
        JoluConnection.connect(context, code)
    }

    Column(
        modifier = modifier
            .fillMaxWidth()
            .padding(top = 32.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {

        Text(
            text = if (state is JoluState.Connected) {
                "JoLu account"
            } else {
                "Connect JoLu account"
            }
        )

        Line(statusText(state))

        when (state) {
            is JoluState.NotConnected, JoluState.Pairing -> {
                val pairing = state == JoluState.Pairing

                OutlinedTextField(
                    value = code,
                    onValueChange = { code = it.take(MAX_CODE_INPUT) },
                    modifier = Modifier.padding(top = 16.dp),
                    enabled = !pairing,
                    singleLine = true,
                    label = { Text("Pairing code") },
                    placeholder = { Text("8 characters") },
                    keyboardOptions = KeyboardOptions(
                        capitalization = KeyboardCapitalization.Characters,
                        autoCorrectEnabled = false,
                        keyboardType = KeyboardType.Ascii,
                        imeAction = ImeAction.Done
                    ),
                    keyboardActions = KeyboardActions(onDone = { submit() })
                )

                Button(
                    onClick = { submit() },
                    enabled = !pairing,
                    modifier = Modifier.padding(top = 12.dp)
                ) {
                    Text("Connect JoLu")
                }
            }

            is JoluState.Connected -> {
                val profile = state.profile
                val targets = state.targets

                Line("Username: ${profile.username ?: NO_DATA}", top = 16.dp)

                Line("Age: ${profile.age?.toString() ?: NO_DATA}", top = 16.dp)
                Line("Gender: ${genderLabel(profile.gender)}")
                Line("Height: ${measurement(profile.heightCm, "cm")}")
                Line("Weight: ${measurement(profile.weightKg, "kg")}")
                Line("Activity: ${activityLabel(profile.activityLevel)}")

                Line("Daily calories: ${amount(targets.calorieTargetKcal, "kcal")}", top = 16.dp)
                Line("Daily protein: ${amount(targets.proteinTargetG, "g")}")
                Line("BMR: ${amount(targets.bmrKcal, "kcal")}")
                Line("TDEE: ${amount(targets.tdeeKcal, "kcal")}")
            }

            is JoluState.Failed -> {
                Button(
                    onClick = { JoluConnection.retry(context) },
                    modifier = Modifier.padding(top = 12.dp)
                ) {
                    Text("Try again")
                }
            }

            JoluState.Checking, JoluState.Loading -> Unit
        }
    }
}

@Composable
private fun Line(text: String, top: Dp = 8.dp) {
    Text(
        text = text,
        modifier = Modifier.padding(top = top)
    )
}

private fun statusText(state: JoluState): String =
    when (state) {
        JoluState.Checking -> "Checking JoLu connection..."
        is JoluState.NotConnected -> state.message ?: "JoLu not connected"
        JoluState.Pairing -> "Connecting to JoLu..."
        JoluState.Loading -> "Loading JoLu profile..."
        is JoluState.Connected -> "JoLu connected"
        is JoluState.Failed -> state.message
    }

/** 172.5 → "172.5 cm", 180.0 → "180 cm", in the phone's number format like the rest of the screen. */
private fun measurement(value: Double?, unit: String): String {
    if (value == null) {
        return NO_DATA
    }

    val number = NumberFormat.getNumberInstance().apply {
        maximumFractionDigits = 1
        isGroupingUsed = false
    }

    return "${number.format(value)} $unit"
}

private fun amount(value: Int?, unit: String): String =
    if (value == null) NO_DATA else "$value $unit"

/**
 * "undisclosed" is what the server stores until somebody answers, so it reads
 * as no data here, as it does on the website.
 */
private fun genderLabel(gender: String?): String =
    when (gender) {
        null, "undisclosed" -> NO_DATA
        "female" -> "Female"
        "male" -> "Male"
        "non_binary" -> "Non-binary"
        "other" -> "Other"
        else -> gender
    }

private fun activityLabel(level: String?): String =
    when (level) {
        null -> NO_DATA
        "sedentary" -> "Sedentary"
        "light" -> "Lightly active"
        "moderate" -> "Moderately active"
        "active" -> "Active"
        "athlete" -> "Athlete"
        else -> level
    }
