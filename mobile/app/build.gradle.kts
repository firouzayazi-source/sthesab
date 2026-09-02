plugins {
    id("com.android.application")
}

android {
    namespace = "ir.stland.daftar"
    compileSdk = 35

    defaultConfig {
        applicationId = "ir.stland.daftar"
        // ۲۱ یعنی اندروید ۵ به بالا. TWA خودش به کروم ۷۲+ نیاز دارد که
        // روی همه‌ی این دستگاه‌ها به‌روز می‌شود.
        minSdk = 21
        targetSdk = 35
        versionCode = 1
        versionName = "1.0"

        // آدرسی که اپ باز می‌کند. کتابخانه‌ی androidbrowserhelper این
        // مقادیر را از manifest می‌خواند و manifest از همین‌جا پر می‌شود،
        // پس آدرس فقط یک جا نوشته شده.
        manifestPlaceholders["hostName"] = "hesab.stland.ir"
        manifestPlaceholders["launchUrl"] = "https://hesab.stland.ir/index.php"
    }

    signingConfigs {
        create("release") {
            // ⚠ کلید از متغیرهای محیطی می‌آید، نه از فایلِ داخل مخزن.
            //
            // امضا باید **همیشه یکی بماند**: اثر انگشتِ همین کلید در
            // `.well-known/assetlinks.json` روی سرور نوشته شده. اگر عوض
            // شود، اندروید تأیید نمی‌کند و اپ با نوار آدرس بالا می‌آید —
            // یعنی دیگر شبیه اپ نیست.
            //
            // به همین دلیل امضا با کلیدِ debug (که هر بار تازه ساخته
            // می‌شود) اصلاً کار نمی‌کند.
            val ks = System.getenv("KEYSTORE_FILE")
            if (ks != null) {
                storeFile = file(ks)
                storePassword = System.getenv("KEYSTORE_PASSWORD")
                keyAlias = "daftar"
                keyPassword = System.getenv("KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    // ⚠ این خط حل‌کننده‌ی یک شکستِ واقعی است، نه احتیاط.
    //
    // `androidbrowserhelper` نسخه‌ی قدیمیِ `kotlin-stdlib-jdk7/jdk8:1.6.21`
    // را می‌آورد و `appcompat` نسخه‌ی `kotlin-stdlib:1.8.22` را. از کاتلین
    // ۱.۸ به بعد محتویاتِ آن دو داخل خودِ `kotlin-stdlib` ادغام شده، پس
    // بودنِ هر دو یعنی هر کلاس دو بار — و ساخت با
    // `checkReleaseDuplicateClasses` می‌ایستد.
    //
    // BOM همه‌ی artifact های کاتلین را روی یک نسخه هم‌تراز می‌کند؛ آنجا
    // `-jdk7`/`-jdk8` پوسته‌های خالی‌اند و تکراری نمی‌سازند. راه دیگر
    // exclude کردنِ دستیِ آن دو بود که شکننده‌تر است: با هر ارتقای
    // کتابخانه باید دوباره وارسی می‌شد.
    implementation(platform("org.jetbrains.kotlin:kotlin-bom:1.9.24"))

    // کل کارِ TWA در همین کتابخانه است و ما هیچ کد جاوا/کاتلینی
    // نمی‌نویسیم — اپ فقط یک اعلان در manifest است.
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.5.0")

    // ⚠ صریح آمده، هرچند شاید کتابخانه‌ی بالا خودش بیاوردش.
    //
    // تمِ اپ `Theme.AppCompat.NoActionBar` است و اگر appcompat نباشد،
    // پروژه اصلاً کامپایل نمی‌شود («resource style/Theme.AppCompat not
    // found»). تمِ AppCompat برای هر اکتیویتی‌ای معتبر است، پس این
    // انتخاب در هر دو حالت امن است — چه LauncherActivity خودش
    // AppCompat باشد چه نباشد.
    implementation("androidx.appcompat:appcompat:1.7.0")
}
