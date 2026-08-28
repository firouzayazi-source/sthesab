<?php
/**
 * تست قرارداد اندپوینت‌های api/ — قواعدی که کل امنیت اپ رویشان بنا شده.
 *
 * این‌ها تا امروز فقط در CLAUDE.md نوشته شده بودند و هیچ چیز جز چشم آدم
 * تضمینشان نمی‌کرد. یک اندپوینت تازه که یادش برود بررسی ورود بگذارد،
 * بی‌سروصدا داده‌ی همه‌ی کاربران را باز می‌کند.
 *
 * این تست ساختار کد را می‌آزماید نه رفتار را، پس بدون دیتابیس و سرور
 * هم اجرا می‌شود. تست رفتاری در test_api_auth.php است.
 */

require_once __DIR__ . '/lib/assert.php';

$apiDir = __DIR__ . '/../api';
$files  = glob($apiDir . '/*.php');
sort($files);

// اندپوینت‌های فقط-خواندنی که عمداً CSRF ندارند.
// اگر روزی یکی از این‌ها نویسنده شد، باید از این فهرست برداشته شود.
$csrfExempt = [
    'category_transactions.php',
    'dashboard_stats.php',
    'day_detail.php',
    'savings_history.php',
    'transaction_attachments.php',
    'view_attachment.php',
];

// ---------------------------------------------------------------
T::group('همه‌ی اندپوینت‌ها پیدا شدند');
T::ok(count($files) >= 40, 'تعداد اندپوینت‌های api/', 'پیدا شد: ' . count($files));

// ---------------------------------------------------------------
T::group('قاعده ۱ — هر اندپوینت باید ورود کاربر را بررسی کند');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    $hasAuthInclude = str_contains($src, "auth.php");
    $hasAuthCheck   = str_contains($src, 'Auth::isLoggedIn')
                   || str_contains($src, 'Auth::requireLogin')
                   || str_contains($src, 'Auth::requireAdmin');
    if (!$hasAuthInclude || !$hasAuthCheck) {
        $bad[] = $name . ($hasAuthInclude ? ' — auth.php لود شده ولی بررسی نمی‌کند' : ' — auth.php اصلاً لود نشده');
    }
}
T::bulk(count($files), $bad, 'هر اندپوینت auth.php را لود و ورود را بررسی می‌کند');

// ---------------------------------------------------------------
T::group('قاعده ۲ — هر اندپوینت نویسنده باید CSRF را بررسی کند');

$bad = [];
$writers = 0;
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    if (in_array($name, $csrfExempt, true)) { continue; }
    $writers++;
    if (!str_contains($src, 'Csrf::verifyOrFail')) {
        $bad[] = "$name — نه در فهرست معافیت است، نه Csrf::verifyOrFail دارد";
    }
}
T::bulk($writers, $bad, 'هر اندپوینت خارج از فهرست معافیت، CSRF را بررسی می‌کند');

// فهرست معافیت نباید بپوسد: فایلی که دیگر وجود ندارد باید حذف شود
$bad = [];
foreach ($csrfExempt as $name) {
    if (!file_exists($apiDir . '/' . $name)) {
        $bad[] = "$name در فهرست معافیت هست ولی فایلش وجود ندارد";
    }
}
T::bulk(count($csrfExempt), $bad, 'فهرست معافیت CSRF به‌روز است');

// معاف‌ها واقعاً باید فقط-خواندنی باشند
$bad = [];
foreach ($csrfExempt as $name) {
    $p = $apiDir . '/' . $name;
    if (!file_exists($p)) { continue; }
    $src = file_get_contents($p);
    if (preg_match('/->\s*(prepare|query)\s*\(\s*["\'][^"\']*\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER)\b/i', $src, $m)) {
        $bad[] = "$name معاف از CSRF است ولی کوئری نویسنده دارد ({$m[2]})";
    }
}
T::bulk(count($csrfExempt), $bad, 'اندپوینت‌های معاف واقعاً فقط-خواندنی‌اند');

// ---------------------------------------------------------------
T::group('قاعده ۳ — user_id هرگز از ورودی کاربر نمی‌آید');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    // گرفتن user_id از POST/GET یعنی هر کاربری می‌تواند خود را جای دیگری بزند
    if (preg_match('/(postParam|getParam)\s*\(\s*[\'"]user_id[\'"]/', $src)) {
        $bad[] = "$name — user_id را از ورودی کاربر می‌خواند";
    }
    if (preg_match('/\$_(POST|GET|REQUEST)\s*\[\s*[\'"]user_id[\'"]\s*\]/', $src)) {
        $bad[] = "$name — user_id را مستقیم از \$_POST/\$_GET می‌خواند";
    }
}
T::bulk(count($files), $bad, 'هیچ اندپوینتی user_id را از ورودی نمی‌گیرد');

// ---------------------------------------------------------------
T::group('قاعده ۴ — کوئری‌ها پارامتری‌اند');

$bad = [];
foreach ($files as $f) {
    $src  = file_get_contents($f);
    $name = basename($f);
    // درج مستقیم متغیر داخل رشته‌ی SQL
    if (preg_match_all('/->\s*(prepare|query)\s*\(\s*"[^"]*\$[A-Za-z_]/', $src, $m)) {
        foreach ($m[0] as $hit) {
            $bad[] = "$name — متغیر داخل رشته‌ی SQL: " . trim(substr($hit, 0, 60));
        }
    }
}
T::bulk(count($files), $bad, 'هیچ متغیری مستقیم داخل رشته‌ی SQL درج نشده');

// ---------------------------------------------------------------
T::group('نحو — هر فایل PHP باید بدون خطا پارس شود');

$bad = [];
$all = [];
foreach (['api', 'includes', 'admin', 'config', '.'] as $dir) {
    foreach (glob(__DIR__ . '/../' . $dir . '/*.php') as $p) { $all[realpath($p)] = true; }
}
foreach (array_keys($all) as $p) {
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { $bad[] = basename($p) . ' — ' . implode(' ', $out); }
    $out = [];
}
T::bulk(count($all), $bad, 'همه‌ی فایل‌های PHP نحو درست دارند');

exit(T::report());
