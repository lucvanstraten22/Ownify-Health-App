plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
}

android {
    namespace = "com.ownify.android"
    compileSdk {
        version = release(37)
    }

    defaultConfig {
        applicationId = "com.ownify.android"
        minSdk = 28
        targetSdk = 37
        versionCode = 4
        versionName = "1.0.4"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
            optimization {
                enable = false
            }
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    buildFeatures {
        compose = true
    }
    testOptions {
        // Robolectric: the Ownify sync tests run WorkManager and the real
        // worker on the JVM, with the app's manifest and resources.
        unitTests {
            isIncludeAndroidResources = true
            all {
                // Robolectric's Android 16 sets up its shared memory through
                // a JDK internal it has to be allowed to reach.
                it.jvmArgs("--add-exports=java.base/jdk.internal.access=ALL-UNNAMED")
                // The screenshots for the parity check with the website
                // (ScreenshotCapture) are only taken when asked for:
                // -Downify.shots=<dir> -Downify.state=<state.json> -Downify.server=<url>
                // (-Downify.state.free: an account with a free goal slot, for the wizard)
                for (key in listOf("ownify.shots", "ownify.state", "ownify.state.free", "ownify.server", "ownify.tree")) {
                    it.systemProperty(key, System.getProperty(key) ?: "")
                }
                // PixelCopy renders in hardware under Robolectric, so glass
                // (RenderEffect blur) is in the screenshots too.
                it.systemProperty("robolectric.pixelCopyRenderMode", "hardware")
            }
        }
    }
}

dependencies {
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)

    implementation("androidx.health.connect:connect-client:1.1.0")

    // The automatic Ownify sync (OwnifyBackgroundSync, OwnifySyncWorker).
    implementation(libs.androidx.work.runtime.ktx)

    // Sign in with Google: Android Credential Manager and Google's ID-token
    // option for it (GoogleSignIn.kt). The ID token goes to the Ownify server,
    // which verifies it (api/auth/app-google.php).
    implementation(libs.androidx.credentials)
    implementation(libs.androidx.credentials.play.services.auth)
    implementation(libs.googleid)

    testImplementation(libs.junit)
    testImplementation(libs.androidx.work.testing)
    testImplementation(libs.androidx.test.core)
    testImplementation(libs.robolectric)
    // The screens, rendered and clicked under Robolectric (ui/OwnifyAppFlowTest and the other ui/ tests).
    testImplementation(platform(libs.androidx.compose.bom))
    testImplementation(libs.androidx.compose.ui.test.junit4)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.compose.ui.test.junit4)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
    debugImplementation(libs.androidx.compose.ui.test.manifest)
    debugImplementation(libs.androidx.compose.ui.tooling)
}