<?php
/**
 * خلاصه‌ی لاگ و آستانه‌های هشدار — «اپ در N ساعتِ گذشته چطور بود؟»
 *
 *     php deploy/log-report.php                 ۲۴ ساعتِ گذشته، خوانا
 *     php deploy/log-report.php --hours 6       بازه‌ی دلخواه
 *     php deploy/log-report.php --json          برای ماشین (مانیتور/هشدار)
 *     php deploy/log-report.php --tail 30       ۳۰ خطِ آخرِ error/warn
 *     php deploy/log-report.php --req 3f2a9c…   همه‌ی خطوطِ یک درخواست
 *
 * ⛔ کدِ خروج: ۰ سالم، ۱ یعنی دست‌کم یک آستانه رد شده (`THRESHOLDS`).
 *    این همان چیزی است که یک cron می‌تواند به هر کانالی که خواست
 *    (تلگرامِ `backup-offsite`، ایمیل) وصل کند — خودِ فرستادن اینجا
 *    نیست، چون امروز هیچ کانالِ هشداری در نصب تعریف نشده.
 *
 * ⛔ آستانه‌ها برای **این** معماری‌اند، نه عددِ عمومی: ۲۷ صفحه‌ی اپ روی
 *    ۲۰٬۰۰۰ تراکنش زیرِ ۶۰ میلی‌ثانیه رندر می‌شوند (`CLAUDE.md`، بخشِ
 *    ایندکس‌ها)، پس p95 بالای ۱٬۵۰۰ یعنی خرابی نه بار. نرخِ ۵xx فقط
 *    با دست‌کم ۲۰ درخواست معنا دارد — دو خطا در پنج درخواستِ شبانه
 *    «۴۰٪» نیست، دو خطاست.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cron_health.php';

const THRESHOLDS = [
    'error_rate'   => 0.02,   // سهمِ ۵xx از کلِ درخواست‌ها (با کفِ ۲۰ درخواست)
    'p95_ms'       => 1500,   // میلی‌ثانیه
    'login_failed' => 30,     // در ساعت
    'db_failed'    => 1,      // کوئریِ شکست‌خورده در بازه
    'cron_stale'   => 1,      // cronِ کهنه/اجرانشده
];

$args  = array_slice($argv, 1);
$hours = 24;
$json  = in_array('--json', $args, true);
$tail  = 0;
$req   = '';
foreach ($args as $i => $a) {
    if ($a === '--hours' && isset($args[$i + 1])) { $hours = max(1, (int)$args[$i + 1]); }
    if ($a === '--tail'  && isset($args[$i + 1])) { $tail  = max(1, (int)$args[$i + 1]); }
    if ($a === '--req'   && isset($args[$i + 1])) { $req   = trim($args[$i + 1]); }
}

$since = time() - $hours * 3600;

/** فایل‌های هر دو کانال که ممکن است خطی در بازه داشته باشند. */
$files = [];
for ($d = 0; $d <= (int)ceil($hours / 24); $d++) {
    $day = date('Y-m-d', time() - $d * 86400);
    foreach (['web', 'cli'] as $ch) {
        $p = Log::dir() . '/' . $ch . '-' . $day . '.log';
        if (is_readable($p)) { $files[] = $p; }
    }
}

// ---------- --req: همه‌ی خطوطِ یک درخواست ----------
if ($req !== '') {
    $n = 0;
    foreach (glob(Log::dir() . '/*.log') ?: [] as $p) {
        $fh = fopen($p, 'r');
        if (!$fh) { continue; }
        while (($ln = fgets($fh)) !== false) {
            if (str_contains($ln, '"req":"' . $req . '"')) { echo $ln; $n++; }
        }
        fclose($fh);
    }
    if ($n === 0) { fwrite(STDERR, "هیچ خطی با این شناسه پیدا نشد: {$req}\n"); exit(1); }
    exit(0);
}

$rows = [];
foreach ($files as $p) {
    foreach (Log::readLines($p, 200000) as $r) {
        $t = strtotime((string)($r['ts'] ?? ''));
        if ($t === false || $t < $since) { continue; }
        $rows[] = $r;
    }
}

// ---------- --tail: خطوطِ مشکل‌دار ----------
if ($tail > 0) {
    $bad = array_values(array_filter($rows, fn($r) => in_array($r['level'] ?? '', ['warn', 'error', 'fatal'], true)));
    foreach (array_slice($bad, -$tail) as $r) {
        printf("%s %-5s %-8s %s %s%s\n", $r['ts'] ?? '', $r['level'] ?? '', $r['svc'] ?? '',
            $r['req'] ?? '', $r['event'] ?? '',
            isset($r['msg']) ? ' — ' . $r['msg'] : (isset($r['status']) ? ' (' . $r['status'] . ', ' . ($r['ms'] ?? '?') . 'ms)' : ''));
    }
    if ($bad === []) { echo "هیچ خطِ warn/error در این بازه نیست.\n"; }
    exit(0);
}

// ---------- خلاصه ----------
$requests = array_values(array_filter($rows, fn($r) => ($r['event'] ?? '') === 'request'));
$n5 = $n4 = $slow = $dbFailed = 0;
$ms = [];
$byRoute = [];
foreach ($requests as $r) {
    $st = (int)($r['status'] ?? 0);
    if ($st >= 500) { $n5++; } elseif ($st >= 400) { $n4++; }
    if (!empty($r['slow'])) { $slow++; }
    $dbFailed += (int)($r['db']['failed'] ?? 0);
    $ms[] = (float)($r['ms'] ?? 0);
    $rt = (string)($r['route'] ?? '?');
    $byRoute[$rt] = ($byRoute[$rt] ?? 0) + 1;
}
sort($ms);
$pct = function (array $s, float $p): float {
    if ($s === []) { return 0.0; }
    return $s[(int)min(count($s) - 1, floor($p * count($s)))];
};

$errors = [];
foreach ($rows as $r) {
    if (!in_array($r['level'] ?? '', ['error', 'fatal'], true)) { continue; }
    if (($r['event'] ?? '') === 'request') { continue; }
    $k = ($r['event'] ?? '?') . ' — ' . mb_substr((string)($r['msg'] ?? ''), 0, 80);
    $errors[$k] = ($errors[$k] ?? 0) + 1;
}
arsort($errors);
$slowQueries = count(array_filter($rows, fn($r) => ($r['event'] ?? '') === 'db.slow_query'));
$clientErr   = count(array_filter($rows, fn($r) => ($r['event'] ?? '') === 'client.error'));
$loginFailed = Audit::countSince('auth.login_failed', $hours);
$cronBad     = array_values(array_filter(CronHealth::status(), fn($c) => $c['state'] !== 'ok'));

$total = count($requests);
$rate  = $total > 0 ? $n5 / $total : 0.0;
$p95   = $pct($ms, 0.95);

$alerts = [];
if ($total >= 20 && $rate > THRESHOLDS['error_rate']) { $alerts[] = sprintf('نرخِ ۵xx %.1f%% (سقف %.0f%%)', $rate * 100, THRESHOLDS['error_rate'] * 100); }
if ($p95 > THRESHOLDS['p95_ms'])                       { $alerts[] = sprintf('p95 زمانِ پاسخ %.0f ms (سقف %d)', $p95, THRESHOLDS['p95_ms']); }
if ($loginFailed > THRESHOLDS['login_failed'] * $hours) { $alerts[] = "{$loginFailed} ورودِ ناموفق در {$hours} ساعت"; }
if ($dbFailed >= THRESHOLDS['db_failed'])               { $alerts[] = "{$dbFailed} کوئریِ شکست‌خورده"; }
if (count($cronBad) >= THRESHOLDS['cron_stale'])        { $alerts[] = count($cronBad) . ' cron کهنه/اجرانشده'; }

$report = [
    'hours' => $hours, 'requests' => $total, 'status_5xx' => $n5, 'status_4xx' => $n4,
    'error_rate' => round($rate, 4), 'p50_ms' => $pct($ms, 0.5), 'p95_ms' => $p95,
    'slow_requests' => $slow, 'slow_queries' => $slowQueries, 'db_failed' => $dbFailed,
    'client_errors' => $clientErr, 'login_failed' => $loginFailed,
    'distinct_errors_db' => AppErrors::countSince(max(1, (int)ceil($hours / 24))),
    'top_errors' => array_slice($errors, 0, 8, true),
    'top_routes' => (function () use ($byRoute) { arsort($byRoute); return array_slice($byRoute, 0, 8, true); })(),
    'cron_problems' => array_map(fn($c) => $c['key'] . ':' . $c['state'], $cronBad),
    'alerts' => $alerts,
    'ok' => $alerts === [],
];

if ($json) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($alerts === [] ? 0 : 1);
}

printf("گزارشِ %d ساعتِ گذشته (%s)\n", $hours, date('Y-m-d H:i'));
printf("  درخواست: %d   ۵xx: %d   ۴xx: %d   نرخِ خطا: %.2f%%\n", $total, $n5, $n4, $rate * 100);
printf("  زمانِ پاسخ p50: %.0f ms   p95: %.0f ms   کند (>%d): %d\n", $pct($ms, 0.5), $p95, Log::SLOW_REQUEST_MS, $slow);
printf("  کوئریِ کند (>%d ms): %d   کوئریِ شکست‌خورده: %d\n", Log::SLOW_QUERY_MS, $slowQueries, $dbFailed);
printf("  خطای مرورگر: %d   ورودِ ناموفق: %d   خطای متمایز در app_errors: %d\n",
    $clientErr, $loginFailed, $report['distinct_errors_db']);
if ($errors) {
    echo "  بیشترین خطاها:\n";
    foreach (array_slice($errors, 0, 8, true) as $k => $c) { printf("    ×%-4d %s\n", $c, $k); }
}
if ($cronBad) {
    echo "  cron:\n";
    foreach ($cronBad as $c) { printf("    %s: %s\n", $c['key'], $c['state']); }
}
if ($files === []) { echo "  ⚠ هیچ فایلِ لاگی در var/log نیست — یا هنوز درخواستی نیامده یا پوشه نوشتنی نیست.\n"; }
echo "\n";
if ($alerts === []) {
    echo "✓ هیچ آستانه‌ای رد نشده.\n";
    exit(0);
}
foreach ($alerts as $a) { echo "⛔ {$a}\n"; }
exit(1);
