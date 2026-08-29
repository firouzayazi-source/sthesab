<?php
/**
 * تست هم‌خوانی PHP و JS در تبدیل تاریخ شمسی.
 *
 * دو پیاده‌سازی مستقل وجود دارد:
 *   includes/functions.php          — برای ذخیره و نمایش سمت سرور
 *   assets/js/jalali-datepicker.js  — برای تقویمی که کاربر با آن تاریخ می‌زند
 *
 * اگر این دو از هم جدا شوند، کاربر تاریخی را انتخاب می‌کند و تاریخ دیگری
 * ذخیره می‌شود؛ بدون هیچ خطایی. این تست همان را می‌گیرد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
// این فایل داخل ریشه‌ی وب است و بدون این نگهبان، هر کسی می‌توانست با
// باز کردن آدرسش در مرورگر تست را روی دیتابیس واقعی اجرا کند — تست‌ها
// کاربر و رکورد می‌سازند و پاک می‌کنند.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}


require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/functions.php';

T::group('هم‌خوانی پیاده‌سازی PHP و JS');

exec('command -v node 2>/dev/null', $o, $rc);
if ($rc !== 0) {
    T::skip('مقایسه‌ی PHP و JS', 'node نصب نیست');
    exit(T::report());
}

[$fromY, $toY] = [1300, 1500];
$cmd = sprintf('node %s %d %d 2>&1', escapeshellarg(__DIR__ . '/jalali_js_dump.js'), $fromY, $toY);
$jsOut = shell_exec($cmd);

if ($jsOut === null || str_starts_with(trim($jsOut), 'ERR_')) {
    T::ok(false, 'بارگذاری ماژول JS', trim((string)$jsOut));
    exit(T::report());
}

// خروجی JS: "jy/jm/jd \t gy-gm-gd \t backJy/backJm/backJd"
$jsMap = [];
foreach (explode("\n", trim($jsOut)) as $line) {
    if ($line === '') { continue; }
    [$key, $greg, $back] = explode("\t", $line);
    $jsMap[$key] = [$greg, $back];
}
T::ok(count($jsMap) > 70000, 'خروجی JS دریافت شد', 'تعداد سطر: ' . count($jsMap));

$mismatch = [];
$checked  = 0;
for ($jy = $fromY; $jy <= $toY; $jy++) {
    $lenEsfand = jalaliMonthLength($jy, 12);
    for ($jm = 1; $jm <= 12; $jm++) {
        $len = ($jm <= 6) ? 31 : (($jm <= 11) ? 30 : $lenEsfand);
        for ($jd = 1; $jd <= $len; $jd++) {
            $key = "$jy/$jm/$jd";
            if (!isset($jsMap[$key])) { continue; }
            $checked++;

            [$gy, $gm, $gd] = jalaliToGregorian($jy, $jm, $jd);
            $phpGreg = "$gy-$gm-$gd";
            [$by, $bm, $bd] = gregorianToJalali($gy, $gm, $gd);
            $phpBack = "$by/$bm/$bd";

            [$jsGreg, $jsBack] = $jsMap[$key];
            if ($phpGreg !== $jsGreg) {
                $mismatch[] = "$key شمسی→میلادی: PHP=$phpGreg  JS=$jsGreg";
            } elseif ($phpBack !== $jsBack) {
                $mismatch[] = "$key میلادی→شمسی: PHP=$phpBack  JS=$jsBack";
            }
        }
    }
}
T::bulk($checked, $mismatch, "PHP و JS برای هر روز نتیجه‌ی یکسان می‌دهند (۱۳۰۰ تا ۱۵۰۰)");

exit(T::report());
