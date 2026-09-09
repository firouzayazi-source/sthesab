<?php
/**
 * صفحه‌ی حریم خصوصی باید از **پیکربندیِ واقعی** حرف بزند.
 *
 * ⛔ چرا این فایل وجود دارد: آن صفحه سال‌ها یک جمله‌ی ثابت داشت —
 *    «هیچ چیزی به سرویس بیرونی فرستاده نمی‌شود» — و روزی که دامنه پشتِ
 *    کلادفلر رفت، همان جمله **دروغ** شد: TLS روی لبه‌ی آن‌ها باز
 *    می‌شود، پس رمز و شماره کارتِ کاربر از دیدشان رد می‌شود. هیچ خطایی
 *    نداد، هیچ تستی قرمز نشد، و `git pull` هم عوضش نمی‌کرد چون جمله
 *    ثابت بود. تنها صفحه‌ای که کلِ ارزشش «راست بودن» است، بی‌صدا دروغ
 *    می‌گفت.
 *
 * ⛔ نامتقارن بودنِ هزینه‌ی اشتباه، شکلِ `cdnInFront()` را تعیین می‌کند:
 *    «CDN هست» گفتن وقتی نیست فقط افشای بیش از حد است (بی‌ضرر)، ولی
 *    «CDN نیست» گفتن وقتی هست یعنی همان دروغ. پس آن تابع فقط می‌تواند
 *    «بله» بگوید: نه نبودِ ثابت و نه یک `false`ِ صریح، سنجشِ واقعی را
 *    ساکت نمی‌کند. مهم‌ترین بررسیِ همین فایل هم همان است.
 *
 * ⚠ قاعده ۳۰ در `test_api_contract.php` *شکل* را می‌سنجد (صفحه از تابع
 *   بخواند، ادعای مطلق فقط داخلِ شاخه‌ی خالی، و مصرف‌کننده `sms.php` را
 *   لود کند). اینجا *رفتار* سنجیده می‌شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('حریم خصوصی');
    T::skip('تست حریم خصوصی', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/sms.php';

/**
 * اجرای یک قطعه در پروسه‌ی جدا — تنها راهِ سنجیدنِ ثابت‌های `config.php`
 * (که در همین پروسه از قبل تعریف شده‌اند).
 */
$sub = function (string $prelude) use ($root): string {
    $code = "<?php error_reporting(0); ini_set('display_errors','0');\n"
          . $prelude . "\n"
          . "require '" . $root . "/includes/db.php';\n"
          . "require '" . $root . "/includes/functions.php';\n"
          . "require '" . $root . "/includes/sms.php';\n"
          . "echo (cdnInFront() ? '1' : '0'), '|',"
          . " implode(',', array_column(outboundDataFlows(), 'key'));\n";
    $tmp = sys_get_temp_dir() . '/priv_probe_' . getmypid() . '_' . mt_rand() . '.php';
    file_put_contents($tmp, $code);
    $out = (string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    return trim($out);
};

// ---------------------------------------------------------------
T::group('۱ — تشخیصِ CDN');

$_SERVER['HTTP_CF_RAY']  = '';
$_SERVER['HTTP_CDN_LOOP'] = '';
T::ok(cdnInFront() === false, 'بدونِ هیچ نشانه‌ای، CDN اعلام نمی‌شود');

$_SERVER['HTTP_CF_RAY'] = '8a1b2c3d4e5f6789-FRA';
T::ok(cdnInFront() === true, 'سرآیندِ CF-Ray یعنی CDN جلوی سایت است');
unset($_SERVER['HTTP_CF_RAY']);

$_SERVER['HTTP_CDN_LOOP'] = 'cloudflare';
T::ok(cdnInFront() === true, 'سرآیندِ CDN-Loop هم شناخته می‌شود');
unset($_SERVER['HTTP_CDN_LOOP']);

T::ok(cdnInFront() === false, 'با برداشتنِ سرآیندها دوباره خاموش می‌شود');

// ⛔ و هیچ سرآیندِ آی‌پی‌ای خوانده نمی‌شود (قاعده ۲۷): جعلِ آن‌ها تصمیمِ
//    اعتماد را عوض می‌کند، ولی این تابع اصلاً به آن‌ها نگاه نمی‌کند.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
$_SERVER['HTTP_X_REAL_IP']       = '1.2.3.4';
T::ok(cdnInFront() === false, '⛔ سرآیندهای آی‌پی هیچ اثری روی این تشخیص ندارند');
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);

// ---------------------------------------------------------------
T::group('۲ — ثابتِ APP_BEHIND_CDN فقط روشن می‌کند');

$on = $sub("define('APP_BEHIND_CDN', true);");
T::same('1', explode('|', $on)[0], 'ثابتِ true بدونِ هیچ سرآیندی هم CDN را اعلام می‌کند');

$off = $sub("define('APP_BEHIND_CDN', false);");
T::same('0', explode('|', $off)[0], 'ثابتِ false و بدونِ سرآیند یعنی خاموش');

// ⛔ مهم‌ترین بررسیِ این فایل: یک `false`ِ صریح نباید واقعیت را ساکت کند.
//    وگرنه مالکِ نصبی که این خط را از روی نمونه کپی کرده و دست نزده،
//    صفحه‌ای می‌گیرد که ادعا می‌کند هیچ چیزی بیرون نمی‌رود — در حالی که
//    کلِ ترافیکش از لبه‌ی یک CDN رد می‌شود.
$offButReal = $sub("define('APP_BEHIND_CDN', false);\n\$_SERVER['HTTP_CF_RAY'] = 'x-FRA';");
T::same('1', explode('|', $offButReal)[0],
    '⛔ `false`ِ صریح هم سنجشِ واقعیِ سرآیند را خاموش نمی‌کند');

// ---------------------------------------------------------------
T::group('۳ — فهرستِ چیزهایی که بیرون می‌روند');

$keys = fn(): array => array_column(outboundDataFlows(), 'key');

T::ok(!in_array('cdn', $keys(), true), 'بدونِ CDN، قلمِ CDN در فهرست نیست');

$_SERVER['HTTP_CF_RAY'] = 'x-FRA';
T::ok(in_array('cdn', $keys(), true), 'با CDN، قلمش به فهرست اضافه می‌شود');
$cdnText = '';
foreach (outboundDataFlows() as $f) { if ($f['key'] === 'cdn') { $cdnText = $f['text']; } }
T::ok(str_contains($cdnText, 'رمز') && str_contains($cdnText, 'شماره کارت'),
    '⛔ متنش صریح می‌گوید رمز و شماره کارت هم از آنجا رد می‌شود');
unset($_SERVER['HTTP_CF_RAY']);

// ایمیل: ثابتِ `MAIL_METHOD` است، پس فقط در پروسه‌ی جدا سنجیدنی است.
$smtp = $sub("define('MAIL_METHOD', 'smtp');");
T::ok(str_contains(explode('|', $smtp)[1] ?? '', 'mail'),
    'با MAIL_METHOD=smtp، قلمِ ایمیل در فهرست می‌آید');

$noMail = $sub("define('MAIL_METHOD', '');");
T::ok(!str_contains(explode('|', $noMail)[1] ?? '', 'mail'),
    'با ایمیلِ خاموش، قلمِ ایمیل در فهرست نیست');

// پیامک: `SMS_METHOD` روی این نصب خالی است، پس `smsSetting()` سراغِ
// `app_settings` می‌رود و همان‌جا سنجیدنی است.
$hadSms = getSetting('sms_method', '');
try {
    setSetting('sms_method', 'kavenegar');
    T::ok(in_array('sms', $keys(), true), 'با پنلِ پیامکِ تنظیم‌شده، قلمِ پیامک می‌آید');

    // ⛔ `log` یعنی «هیچ‌جا فرستاده نمی‌شود» (فقط var/sms.log)، پس ادعای
    //    «به پنلِ بیرونی می‌رود» درباره‌اش دروغ است.
    setSetting('sms_method', 'log');
    T::ok(!in_array('sms', $keys(), true),
        '⛔ حالتِ log هیچ چیزی بیرون نمی‌فرستد، پس در فهرست نمی‌آید');

    forgetSetting('sms_method');
    T::ok(!in_array('sms', $keys(), true), 'بدونِ پنلِ پیامک، قلمِ پیامک نیست');
} finally {
    if ($hadSms !== '') { setSetting('sms_method', $hadSms); }
    else { forgetSetting('sms_method'); }
}

// ---------------------------------------------------------------
T::group('۴ — ادعای مطلق فقط وقتی فهرست خالی است');

$_SERVER['HTTP_CF_RAY'] = '';
$_SERVER['HTTP_CDN_LOOP'] = '';
unset($_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CDN_LOOP']);

$privacy = (string)file_get_contents($root . '/privacy.php');
T::ok(str_contains($privacy, 'outboundDataFlows()'),
    'privacy.php فهرست را از همان تابع می‌گیرد، نه از متنِ خودش');
T::ok(str_contains($privacy, "includes/sms.php"),
    '⛔ privacy.php کلاسِ Sms را لود می‌کند، وگرنه قلمِ پیامک بی‌صدا می‌افتد');

exit(T::report());
