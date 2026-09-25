plugins {
    id("com.android.application")
}

android {
    // ⛔ `namespace` و `applicationId` عمداً یکی‌اند و **تنها مرجعِ** نامِ
    //    بسته‌اند. چهار جای دیگر باید با همین بخوانند و هیچ‌کدام خودش
    //    مرجع نیست: پوشه‌ی سورسِ جاوا و خطِ `package` هر دو فایل،
    //    `action` صفحه‌ی تنظیمِ پیامک در manifest، لینکِ `intent://` در
    //    `profile.php`، و `package_name` در `.well-known/assetlinks.json`.
    //    اگر یکی جا بماند، خرابی **بی‌صداست**: یا اپ با نوار آدرس بالا
    //    می‌آید (assetlinks)، یا دکمه‌ی «تنظیم در اپ اندروید» زده می‌شود و
    //    هیچ اتفاقی نمی‌افتد (intent). قاعده ۱۲ در `test_api_contract.php`
    //    هر چهار را با همین خط می‌سنجد.
    namespace = "ir.stland.hesabland"
    compileSdk = 35

    defaultConfig {
        applicationId = "ir.stland.hesabland"
        // ۲۱ یعنی اندروید ۵ به بالا. TWA خودش به کروم ۷۲+ نیاز دارد که
        // روی همه‌ی این دستگاه‌ها به‌روز می‌شود.
        minSdk = 21
        targetSdk = 35
        versionCode = 11
        versionName = "2.0"

        // آدرسی که اپ باز می‌کند. کتابخانه‌ی androidbrowserhelper این
        // مقادیر را از manifest می‌خواند و manifest از همین‌جا پر می‌شود،
        // پس آدرس فقط یک جا نوشته شده.
        manifestPlaceholders["hostName"] = "hesab.stland.ir"
        manifestPlaceholders["launchUrl"] = "https://hesab.stland.ir/index.php"

        // ⛔ همان آدرس، این بار برای کدِ جاوا — و از **همان یک منبع**.
        //
        //    `BankSmsReceiver` باید آدرسی بسازد که اعلان بازش کند. اگر
        //    آن آدرس در `strings.xml` دستی نوشته می‌شد، عوض کردنِ دامنه
        //    یکی از این دو را جا می‌گذاشت و اعلانِ پیامک بی‌صدا به
        //    دامنه‌ی قدیمی می‌رفت — یعنی تپ می‌کردی و هیچ اتفاقی
        //    نمی‌افتاد. با `resValue` هر دو از یک خط می‌آیند.
        resValue("string", "launch_url", manifestPlaceholders["launchUrl"] as String)
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
                // ⛔ نامِ کلید عمداً با برند عوض **نشد** و نباید بشود.
                //    این رشته نامِ ما نیست، نامِ ورودیِ داخلِ همان فایلِ
                //    keystore ای است که در `KEYSTORE_BASE64` نشسته. عوض
                //    کردنش یعنی `keytool` آن ورودی را پیدا نمی‌کند، امضا
                //    شکست می‌خورد، و ساخت با «keystore password was
                //    incorrect» می‌ایستد — پیامی که هیچ ربطی به علت ندارد.
                //    (همین رشته در `.github/workflows/android.yml` هم هست.)
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
