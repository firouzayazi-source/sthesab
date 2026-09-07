<?php
/**
 * تستِ پیشنهادِ نصبِ اپ اندروید.
 *
 * ⛔ چیزی که این تست نگه می‌دارد، سه خرابیِ **بی‌صدا**ست:
 *   ۱. نوار وقتی رندر شود که هیچ APK ای وجود ندارد → کاربر «دریافت اپ»
 *      را می‌زند و ۴۰۴ می‌گیرد. «دکمه‌ای که کار نمی‌کند بدتر از نبودنش
 *      است» و اینجا حتی بدتر: روی صفحه‌ی ورود، به کسی که هنوز هیچ
 *      تجربه‌ای از اپ ندارد.
 *   ۲. نوار روی دسکتاپ یا آیفون رندر شود → پیشنهادِ فایلی که آن دستگاه
 *      اصلاً نمی‌تواند نصبش کند.
 *   ۳. نگهبانِ `android-app://` از جاوااسکریپت بیفتد → کاربری که اپ را
 *      **نصب کرده** داخلِ خودِ اپ پیشنهادِ نصب می‌بیند. این تنها نشانه‌ی
 *      قابل اتکاست: TWA با کرومِ معمولی رندر می‌شود و User-Agent اش هیچ
 *      فرقی با مرورگر ندارد، پس سرور از پسش برنمی‌آید.
 *
 * ⚠ لایه‌ی سوم فقط **شکل** سنجیده می‌شود (وجودِ نگهبان در متنِ خروجی)،
 *   نه رفتار: برای رفتارش یک TWA واقعی لازم است. ولی همین جلوی حذفِ
 *   تصادفی‌اش را می‌گیرد، که محتمل‌ترین راهِ از دست رفتنش است.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('پیشنهاد نصب اپ اندروید');
    T::skip('تست پیشنهاد نصب', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$root = dirname(__DIR__);
$apk  = $root . '/download/daftar.apk';

// ⛔ فایلِ واقعیِ کاربر نباید قربانیِ تست شود: اگر از قبل APK ای هست،
//   کنار گذاشته و در پایان برگردانده می‌شود — حتی اگر تست وسطِ کار
//   بمیرد (`register_shutdown_function`).
$hadApk = is_file($apk);
$backup = $apk . '.testbak';
if ($hadApk) { rename($apk, $backup); }

register_shutdown_function(function () use ($apk, $backup, $hadApk) {
    if (is_file($apk) && !$hadApk) { @unlink($apk); }
    if (is_file($backup)) { @rename($backup, $apk); }
    @rmdir(dirname($apk));
});

$ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 '
         . '(KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
$IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 '
         . '(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
$DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
         . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

/** نوار را با یک User-Agent مشخص رندر کن. */
$render = function (string $ua): string {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    return androidInstallBanner();
};

// ---------------------------------------------------------------
T::group('⛔ بدون فایل APK هیچ چیزی رندر نمی‌شود');

T::same('', androidApkUrl(), 'آدرسِ دانلود خالی است');
T::same('', $render($ANDROID),
    '⛔ حتی روی اندروید هم نوار نمی‌آید — لینک به ۴۰۴ بدتر از نبودنش است');

// ---------------------------------------------------------------
T::group('با فایل APK، فقط روی اندروید');

// یک APK ساختگی: محتوایش مهم نیست، فقط وجودش.
@mkdir(dirname($apk), 0755, true);
file_put_contents($apk, "PK\x03\x04" . str_repeat('x', 64));

$url = androidApkUrl();
T::ok($url !== '', 'با وجود فایل، آدرس ساخته می‌شود', $url);
T::ok(str_contains($url, 'daftar.apk'), 'و به همان فایل اشاره می‌کند');
T::ok(str_contains($url, '?v='),
    '⚠ نسخه در آدرس هست، وگرنه به‌روزرسانی به دستِ کسی که یک بار دانلود کرده نمی‌رسد');

$onAndroid = $render($ANDROID);
T::ok($onAndroid !== '', 'روی اندروید نوار رندر می‌شود');
T::ok(str_contains($onAndroid, 'apk-bar'), 'و کلاسِ خودش را دارد');
T::ok(str_contains($onAndroid, h($url)), 'و لینکش همان آدرسِ دانلود است');

T::same('', $render($IPHONE),
    '⛔ روی آیفون رندر نمی‌شود — APK آنجا نصب‌شدنی نیست');
T::same('', $render($DESKTOP), '⛔ روی دسکتاپ هم رندر نمی‌شود');
T::same('', $render(''), 'بدون User-Agent هم رندر نمی‌شود');

// ---------------------------------------------------------------
T::group('⛔ نگهبان‌هایی که «داخلِ خودِ اپ» را ساکت می‌کنند');

T::ok(str_contains($onAndroid, 'android-app://'),
    '⛔ نشانه‌ی TWA سنجیده می‌شود — وگرنه کاربری که اپ را نصب کرده '
    . 'داخلِ همان اپ پیشنهادِ نصب می‌بیند');
T::ok(str_contains($onAndroid, 'display-mode: standalone'),
    'حالتِ نصب‌شده هم ساکت می‌کند');
T::ok(str_contains($onAndroid, 'daftar_apk_hide'),
    'بستنِ دستیِ کاربر به خاطر می‌ماند');
T::ok(str_contains($onAndroid, 'daftar_has_apk'),
    '⛔ نشانه‌ی «اپ نصب است» ذخیره می‌شود — referrer فقط در اولین ناوبری '
    . 'می‌آید و بدونِ ذخیره، از صفحه‌ی دوم دوباره پیشنهاد می‌داد');

// ---------------------------------------------------------------
T::group('⛔ نوار چیدمانِ صفحه را به خطر نمی‌اندازد');

/*
 * `position: fixed` اینجا یعنی درگیری با `.bottom-nav` و
 * `env(safe-area-inset-bottom)` — همان دسته خرابی که در این پروژه بارها
 * فقط روی گوشیِ نصب‌شده دیده شد و در مرورگرِ دسکتاپ بازتولید نمی‌شود.
 */
$css = (string)file_get_contents($root . '/assets/css/style.css');
if (preg_match('/\.apk-bar\s*\{([^}]*)\}/', $css, $m)) {
    T::ok(!str_contains($m[1], 'position: fixed'),
        '⛔ نوار شناور نیست', trim($m[1]));
} else {
    T::ok(false, 'قاعده‌ی .apk-bar در style.css پیدا شد');
}

// ⚠ قاعده ۱۸: کلاسی که `display` می‌گیرد و با `hidden` رندر می‌شود،
//   باید `[hidden]`ِ خودش را داشته باشد — وگرنه نوار پیش از تصمیمِ
//   جاوااسکریپت یک لحظه دیده می‌شود.
T::ok(str_contains($css, '.apk-bar[hidden]'),
    '⛔ قاعده‌ی [hidden] خودش را دارد');
T::ok(str_contains($onAndroid, 'hidden'),
    'و نوار پنهان رندر می‌شود تا جاوااسکریپت تصمیم بگیرد');

// ---------------------------------------------------------------
T::group('اسکریپتِ گذاشتنِ APK');

$sh = (string)@file_get_contents($root . '/deploy/apk-publish.sh');
T::ok($sh !== '', 'deploy/apk-publish.sh وجود دارد');
T::ok(str_contains($sh, 'AndroidManifest.xml'),
    '⛔ فایل را واقعاً می‌سنجد، نه فقط پسوندش — فایلِ اشتباه روی گوشی '
    . 'فقط «برنامه نصب نشد» می‌دهد و علتش را نمی‌گوید');
T::ok(str_contains($sh, 'mv -f'),
    '⚠ جابه‌جاییِ اتمی: دانلودِ همان لحظه نصفه نمی‌شود');

/*
 * ⛔ گرفتن از GitHub Release: مخزن خصوصی است، پس روی سرور بدونِ توکن
 *   ۴۰۴ می‌آید. توکن اما **نباید روی خطِ فرمان برود** — روی این VPS
 *   سرویس‌های دیگری هم هستند و `ps aux` را هر کاربری می‌بیند. همان
 *   دلیلی که `perf-report.sh` رمزِ دیتابیس را با `--defaults-extra-file`
 *   می‌فرستد.
 */
T::ok(str_contains($sh, '--from-github'),
    'اسکریپت می‌تواند APK را مستقیم از Release بگیرد');
T::ok(str_contains($sh, '--config'),
    '⛔ توکن از راهِ فایلِ پیکربندیِ curl می‌رود');
T::ok(!preg_match('/-H\s*["\']?\s*Authorization/i', $sh),
    '⛔ سرآیندِ توکن روی argv نمی‌رود — وگرنه در `ps aux` دیده می‌شد');
T::ok((bool)preg_match('/chmod\s+600\s+"?\$CURL_CFG"?/', $sh),
    'فایلِ توکن فقط برای خودِ root خواندنی است');
T::ok(str_contains($sh, 'trap cleanup EXIT'),
    'و با trap پاک می‌شود، حتی اگر اسکریپت وسطِ کار بمیرد');

exit(T::report());
