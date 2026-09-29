package com.ownify.android

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Text
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier

class PermissionsRationaleActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContent {
            Column(
                modifier = Modifier.fillMaxSize(),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center
            ) {
                Text(
                    text = "Ownify reads your steps, distance, active calories, heart rate, sleep, " +
                        "exercise and nutrition from Health Connect and sends them to your own " +
                        "Ownify account. With background access it also does this about once an " +
                        "hour while the app is closed. Ownify only reads: it never changes or " +
                        "deletes anything in Health Connect."
                )
            }
        }
    }
}