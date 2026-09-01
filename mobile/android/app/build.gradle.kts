plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "ir.stland.daftar"
    // ⚠ عمداً `flutter.compileSdkVersion` نیست.
    //
    // فلاتر ۳.۴۷ روی ۳۶ است، ولی `flutter_secure_storage` ۱۱ صریحاً
    // ۳۷ می‌خواهد و ساخت با پیام
    // «Dependency ':flutter_secure_storage' requires ... version 37 or later»
    // در `:app:checkReleaseAarMetadata` می‌ایستد. آن پکیج جای توکن است،
    // پس پایین آوردنش برای عبور از این خطا، امنیت را قربانی راحتی می‌کرد.
    //
    // بالا بردن compileSdk فقط یعنی «اجازه‌ی دیدنِ APIهای تازه‌تر»؛ به
    // `minSdk` و `targetSdk` دست نمی‌زند، پس نه دستگاهی از دست می‌رود و
    // نه رفتار زمانِ اجرا عوض می‌شود.
    //
    // وقتی فلاتر خودش به ۳۷ رسید، این خط برداشته شود و به
    // `flutter.compileSdkVersion` برگردد.
    compileSdk = 37
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "ir.stland.daftar"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        // Uses the version code from pubspec.yaml. When using split APKs, 1000 * ABI_VERSION
        // is added automatically by Flutter. (https://developer.android.com/studio/build/configure-apk-splits#configure-APK-versions)
        // You can force using the value of versionCode by specifying the `-P force-version-code-ignoring-abi=true`
        // flag during build.
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
