<?php
/**
 * تست رفتاری اندپوینت‌ها — واقعاً درخواست می‌فرستد.
 *
 * تست قرارداد (test_api_contract.php) شکل کد را می‌آزماید؛ این یکی رفتار
 * واقعی را. اگر کسی بررسی ورود را بگذارد ولی جایش اشتباه باشد (مثلاً بعد
 * از کوئری)، آن تست موفق می‌شود و این یکی شکست می‌خورد.
 *
 * ایمنی: فقط درخواست‌های بدون ورود می‌فرستد. چنین درخواستی طبق تعریف
 * نباید چیزی بنویسد، پس اجرای این تست حتی روی دیتابیس واقعی هم داده را
 * تغییر نمی‌دهد. اگر تغییری بدهد، خودش همان باگی است که دنبالش هستیم.
 */

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

T::group('آماده‌سازی سرور آزمایشی');

if (!file_exists($root . '/config/config.php')) {
    T::skip('تست رفتاری اندپوینت‌ها', 'config/config.php وجود ندارد');
    exit(T::report());
}

// پورت آزاد پیدا کن
$port = 0;
for ($p = 8971; $p <= 8999; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $errno, $errstr);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::skip('تست رفتاری اندپوینت‌ها', 'پورت آزاد پیدا نشد');
    exit(T::report());
}

$log = tempnam(sys_get_temp_dir(), 'apitest');
$cmd = sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log));
$pid = (int)trim((string)shell_exec($cmd));

// صبر تا بالا آمدن
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}
if (!$up) {
    T::ok(false, 'سرور آزمایشی بالا آمد', 'لاگ: ' . substr((string)@file_get_contents($log), 0, 300));
    if ($pid) { @exec("kill $pid 2>/dev/null"); }
    exit(T::report());
}
T::pass("سرور آزمایشی روی پورت $port بالا آمد");

/** یک درخواست بدون کوکی و بدون نشست */
$req = function (string $path, string $method = 'POST') use ($port): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $method === 'POST' ? 'x=1' : null,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

// ---------------------------------------------------------------
T::group('بدون ورود، هیچ اندپوینتی نباید داده بدهد');

$files = glob($root . '/api/*.php');
sort($files);
$bad = [];
foreach ($files as $f) {
    $name = basename($f);
    [$code, $body] = $req("/api/$name");

    // ۴۰۱ پاسخ درست است. ۴۰۵ هم پذیرفتنی است اگر متد را زودتر رد کند،
    // ولی فقط وقتی بدنه هیچ داده‌ای لو نداده باشد.
    if ($code === 401) { continue; }
    if ($code === 403) { continue; }   // CSRF زودتر رد کرده — هنوز امن است

    if ($code === 200) {
        $bad[] = "$name → ۲۰۰ برگرداند! بدنه: " . substr(preg_replace('/\s+/', ' ', $body), 0, 90);
    } elseif ($code === 500) {
        $bad[] = "$name → خطای ۵۰۰ (به‌جای رد کردن مؤدبانه): "
               . substr(preg_replace('/\s+/', ' ', $body), 0, 90);
    } else {
        $bad[] = "$name → کد $code (انتظار ۴۰۱)";
    }
}
T::bulk(count($files), $bad, 'هر اندپوینت بدون ورود، دسترسی را رد می‌کند');

// ---------------------------------------------------------------
T::group('صفحات کاربر بدون ورود به صفحه‌ی ورود می‌روند');

$pages = ['dashboard.php', 'transactions.php', 'wallets.php', 'budget.php',
          'debts.php', 'cheques.php', 'savings.php', 'my-assets.php',
          'recurring.php', 'search.php', 'calendar.php', 'profile.php'];
$bad = [];
foreach ($pages as $p) {
    [$code, $body] = $req("/$p", 'GET');
    // انتظار: ریدایرکت به login (۳۰۲) — نه نمایش محتوا و نه خطای ۵۰۰
    if ($code === 302 || $code === 303) { continue; }
    $bad[] = "$p → کد $code (انتظار ۳۰۲ به صفحه‌ی ورود)";
}
T::bulk(count($pages), $bad, 'صفحات محافظت‌شده بدون ورود محتوا نشان نمی‌دهند');

// ---------------------------------------------------------------
T::group('فایل‌های حساس از راه وب در دسترس نیستند');

$secret = [
    '/config/config.php'    => 'تنظیمات دیتابیس',
    '/schema.sql'           => 'ساختار دیتابیس',
    '/migration_p4.sql'     => 'فایل migration',
    '/deploy.sh'            => 'اسکریپت استقرار',
    '/CLAUDE.md'            => 'مستندات داخلی',
];
$bad = [];
foreach ($secret as $path => $what) {
    [$code, $body] = $req($path, 'GET');
    // config.php وقتی اجرا شود بدنه‌ی خالی و ۲۰۰ می‌دهد — لو نمی‌رود.
    // بقیه نباید محتوایشان برگردد.
    if ($path === '/config/config.php') {
        if (trim($body) !== '') { $bad[] = "$path محتوا برگرداند ($what)"; }
        continue;
    }
    if ($code === 200 && trim($body) !== '') {
        $bad[] = "$path با کد ۲۰۰ محتوا برگرداند ($what) — روی سرور واقعی nginx مسدودش می‌کند";
    }
}
// این تست روی سرور داخلی PHP اجرا می‌شود که قواعد nginx را ندارد،
// پس نتیجه‌اش فقط اطلاع‌رسانی است نه شکست.
if ($bad) {
    T::skip('مسدود بودن فایل‌های حساس', count($bad) . ' مورد — سرور داخلی PHP قواعد nginx را ندارد');
    foreach ($bad as $b) { printf("        \033[0;90m· %s\033[0m\n", $b); }
} else {
    T::pass('فایل‌های حساس محتوا برنمی‌گردانند');
}

// ---------------------------------------------------------------
if ($pid) { @exec("kill $pid 2>/dev/null"); }
@unlink($log);

exit(T::report());
