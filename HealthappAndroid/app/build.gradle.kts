plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
}

android {
    namespace = "com.healthapp.android"
    compileSdk {
        version = release(37)
    }

    defaultConfig {
        applicationId = "com.healthapp.android"
        minSdk = 28
        targetSdk = 37
        versionCode = 1
        versionName = "1.0"

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
        // Robolectric: the JoLu sync tests run WorkManager and the real
        // worker on the JVM, with the app's manifest and resources.
        unitTests {
            isIncludeAndroidResources = true
            all {
                // Robolectric's Android 16 sets up its shared memory through
                // a JDK internal it has to be allowed to reach.
                it.jvmArgs("--add-exports=java.base/jdk.internal.access=ALL-UNNAMED")
                // The screenshots for the parity check with the website
                // (ScreenshotCapture) are only taken when asked for:
                // -Djolu.shots=<dir> -Djolu.state=<state.json> -Djolu.server=<url>
                // (-Djolu.state.free: an account with a free goal slot, for the wizard)
                for (key in listOf("jolu.shots", "jolu.state", "jolu.state.free", "jolu.server", "jolu.tree")) {
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

    // The automatic JoLu sync (JoluBackgroundSync, JoluSyncWorker).
    implementation(libs.androidx.work.runtime.ktx)

    testImplementation(libs.junit)
    testImplementation(libs.androidx.work.testing)
    testImplementation(libs.androidx.test.core)
    testImplementation(libs.robolectric)
    // The temporary sign-in screen, rendered and clicked under Robolectric (JoluAuthScreenTest).
    testImplementation(platform(libs.androidx.compose.bom))
    testImplementation(libs.androidx.compose.ui.test.junit4)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.compose.ui.test.junit4)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
    debugImplementation(libs.androidx.compose.ui.test.manifest)
    debugImplementation(libs.androidx.compose.ui.tooling)
}