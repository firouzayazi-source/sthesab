<?php
/**
 * تستِ منطقه‌ی زمانی — دسته‌ای از باگ که تا امروز **هیچ تستی اجرایش نمی‌کرد**.
 *
 * ⛔ چرا وجود دارد: پروژه یک بار از همین ضربه خورده (لینکِ بازیابیِ رمز
 *    که بلافاصله «منقضی» می‌شد، چون PHP زمان را می‌ساخت و MySQL مقایسه
 *    می‌کرد). قاعده‌اش نوشته شد — «زمان را در دیتابیس بساز و در دیتابیس
 *    مقایسه کن» — ولی هیچ تستی آن را نمی‌سنجید، چون تست‌های خط فرمان
 *    هرگز `Auth::initSession()` را صدا نمی‌زنند و آنجا تنها جایی بود که
 *    `APP_TIMEZONE` اعمال می‌شد. پس در تست، PHP و MySQL اتفاقاً هر دو
 *    UTC بودند و کلِ این دسته نامرئی می‌ماند.
 *
 * ⛔ و همان اولین اندازه‌گیری یک اختلافِ **زنده** پیدا کرد: PHP روی
 *    ۱۱:۳۰ و `SELECT NOW()` روی ۰۸:۰۰ — سه ساعت و نیم. بی‌خطر به نظر
 *    می‌رسید (چون مقایسه‌ها داخلِ دیتابیس‌اند) ولی فقط تا مرزِ **روز**:
 *    هر شب بین ۰۰:۰۰ و ۰۳:۳۰ به وقتِ تهران، `today()`ِ PHP و
 *    `CURDATE()`ِ دیتابیس دو روزِ متفاوت می‌گفتند.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

T::group('منطقه‌ی زمانی');

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::blocked('تست منطقه‌ی زمانی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';

if (!defined('APP_TIMEZONE') || APP_TIMEZONE === '') {
    T::skip('تست منطقه‌ی زمانی', 'APP_TIMEZONE در config تعریف نشده');
    exit(T::report());
}

// ============================================================
// ۱. خودِ لودِ db.php باید منطقه‌ی زمانی را بگذارد
// ============================================================

T::same(APP_TIMEZONE, date_default_timezone_get(),
    'لودِ db.php منطقه‌ی زمانیِ اپ را اعمال می‌کند');

T::ok(function_exists('applyAppTimezone'),
    'تنها جای این تصمیم (`applyAppTimezone`) وجود دارد');

/**
 * ⛔ و باید بر تنظیمِ سیستم **غلبه** کند، نه اینکه اتفاقاً با آن یکی باشد.
 *    روی این ماشین سیستم UTC است، پس بررسیِ بالا به‌تنهایی می‌تواند با یک
 *    `applyAppTimezone()`ِ خالی هم سبز بماند اگر روزی APP_TIMEZONE هم UTC
 *    شود. اینجا عمداً یک منطقه‌ی زمانیِ متفاوت به پروسه تحمیل می‌شود.
 */
$php  = PHP_BINARY;
$root = dirname(__DIR__);
$forced = APP_TIMEZONE === 'UTC' ? 'Asia/Tokyo' : 'UTC';

$cmd = 'TZ=' . escapeshellarg($forced) . ' '
     . escapeshellarg($php) . ' -d ' . escapeshellarg('date.timezone=' . $forced)
     . ' -r ' . escapeshellarg('require ' . var_export($root . '/includes/db.php', true)
                               . '; echo date_default_timezone_get();')
     . ' 2>/dev/null';

$out = trim((string)shell_exec($cmd));
T::same(APP_TIMEZONE, $out,
    "منطقه‌ی زمانیِ اپ بر تنظیمِ سیستم غلبه می‌کند (سیستم روی {$forced})");

// ============================================================
// ۲. ساعتِ PHP و ساعتِ دیتابیس باید یکی باشند
// ============================================================

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('ساعتِ PHP و دیتابیس', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$sqlNow = (string)$pdo->query('SELECT NOW()')->fetchColumn();
$phpNow = date('Y-m-d H:i:s');

$drift = abs(strtotime($sqlNow) - strtotime($phpNow));
T::ok($drift <= 90,
    'ساعتِ دیتابیس با ساعتِ PHP یکی است',
    $drift <= 90 ? '' : "اختلاف {$drift} ثانیه — PHP: {$phpNow}  دیتابیس: {$sqlNow}");

/**
 * ⛔ مهم‌ترین بررسیِ این فایل. اختلافِ ساعت تا وقتی زیرِ یک روز است بی‌صدا
 *    می‌ماند، ولی `today()`ِ PHP در برابر `CURDATE()`ِ دیتابیس همان چیزی
 *    است که نگهبانِ «یک بار در روز»، کلیدِ یکتای `snap_date` و
 *    `remind_date <= today` رویش سوارند.
 */
$sqlToday = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
T::same(date('Y-m-d'), $sqlToday,
    '«امروز»ِ PHP با «امروز»ِ دیتابیس یکی است');

/**
 * ⚠ افست عددی، نه نامِ منطقه: `SET time_zone = 'Asia/Tehran'` فقط وقتی
 *   کار می‌کند که جدول‌های `mysql.time_zone` بارگذاری شده باشند — که روی
 *   بیشترِ نصب‌ها نیستند. آن‌وقت دستور خطا می‌داد و اتصال می‌مرد.
 */
$tz = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
T::ok((bool)preg_match('/^[+-]\d{2}:\d{2}$/', $tz),
    'منطقه‌ی زمانیِ نشستِ دیتابیس افستِ عددی است، نه نامِ منطقه',
    "مقدار: {$tz}");

// ============================================================
// ۳. نسخه‌ی دومِ این تصمیم وجود نداشته باشد
// ============================================================

$bad = [];
foreach (['includes', 'api', 'admin', 'deploy'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $path = $f->getPathname();
        if (str_ends_with($path, 'includes/db.php')) { continue; }   // خودِ مرجع

        // با توکنایزر، وگرنه همین توضیح هم شمرده می‌شد
        foreach (token_get_all((string)file_get_contents($path)) as $t) {
            if (is_array($t) && $t[0] === T_STRING && $t[1] === 'date_default_timezone_set') {
                $bad[] = str_replace($root . '/', '', $path) . ':' . $t[2];
            }
        }
    }
}
T::bulk(1, $bad, '`date_default_timezone_set` فقط در `applyAppTimezone` است');

exit(T::report());
