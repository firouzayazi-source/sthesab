<?php
/**
 * ⛔ رنگِ فروشگاه — منوی کناریِ طیف‌دار که با پالت عوض می‌شود.
 *
 * **خواسته‌ی مالکِ نصب:** «ساید بار با تغییرِ رنگ تغییر کنه، طیف‌طور و
 * خوشگل بر طبقِ رنگ‌های تعریف‌شده… در بخشِ فروشگاهی هم همین استایل.»
 *
 * چهار خطر، همه بی‌صدا:
 *   ۱. پالتی در `Biz::PALETTES` باشد و در `store.css` نه (یا فقط روز، نه
 *      شب) — انتخابش هیچ اثری ندارد یا در شب رنگِ روز می‌ماند.
 *   ۲. طیف متنِ منو را ناخوانا کند (نارنجیِ روشن زیرِ سفید).
 *   ۳. ذخیره‌ی پیش‌فرض با نام (نه NULL) — پیش‌فرضِ فردا به کسی نمی‌رسد.
 *   ۴. رنگِ یک فروشگاه روی فروشگاهِ دیگر بنشیند، یا نامِ دلخواه روی `<html>`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signup.php';
require_once __DIR__ . '/../includes/user_data.php';

$root = dirname(__DIR__);
const TPREFIX = '__bthm_';
const TPASS   = 'Theme12345';

$css = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($root . '/assets/css/store.css'));

/** @return array<string,string> */
function thTokens(string $body): array
{
    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/', $body, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) { $out[$x[1]] = trim($x[2]); }
    return $out;
}
/** همه‌ی بدنه‌های قاعده با این انتخابگرِ دقیق، به ترتیبِ فایل، با آفست. */
function thBlocks(string $css, string $sel): array
{
    preg_match_all('/(?:^|[{}])\s*' . preg_quote($sel, '/') . '\s*\{([^{}]*)\}/', $css, $m, PREG_OFFSET_CAPTURE);
    return array_map(fn($x) => [$x[0], $x[1]], $m[1]);
}
function thLum(array $c): float
{
    $v = [];
    foreach ($c as $x) { $x /= 255; $v[] = $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4; }
    return 0.2126 * $v[0] + 0.7152 * $v[1] + 0.0722 * $v[2];
}
function thCr(array $a, array $b): float
{
    $x = thLum($a); $y = thLum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}
function thHex(string $h): array { $h = ltrim($h, '#'); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; }
function thMix(array $a, array $b, float $t): array { return [$a[0]*$t + $b[0]*(1-$t), $a[1]*$t + $b[1]*(1-$t), $a[2]*$t + $b[2]*(1-$t)]; }

// ---------------------------------------------------------------
T::group('۱ — هر پالت در CSS کامل است (روز و شب)، و هیچ پالتی یتیم نیست');
// ---------------------------------------------------------------
$keys = array_keys(Biz::PALETTES);
$def  = $keys[0] ?? '';
T::same('amber', $def, 'کهربایی پیش‌فرض است (فروشگاه و حساب لند قاطی نشوند)');
T::ok(count($keys) >= 5, 'دست‌کم پنج رنگ', (string)count($keys));

$darkAt = strpos($css, '@media (prefers-color-scheme: dark)');
$roots  = thBlocks($css, ':root');
$rootRb = array_values(array_filter($roots, fn($b) => str_contains($b[0], '--st-rb1')));
T::ok(count($rootRb) === 2 && $rootRb[1][1] > (int)$darkAt, 'پیش‌فرض --st-rb1..3 را در :root روز و شب دارد (خودِ :root، نه ویژگی)');
T::ok(!str_contains($css, 'html[data-st-palette="' . $def . '"]'), 'پیش‌فرض بلوکِ data-st-palette ندارد');

$need = ['st-rb1', 'st-rb2', 'st-rb3', 'st-accent', 'st-accent-2', 'st-accent-ink', 'st-wash'];
$pal = [];
$badP = [];
$rootL = []; $rootD = [];
foreach ($roots as [$body, $off]) {
    $t = thTokens($body);
    // اولین :root (بالای فایل) روز، آنچه داخلِ مدیای شب است شب
    $inDark = false;
    $open = strrpos(substr($css, 0, $off), '@media (prefers-color-scheme: dark)');
    if ($open !== false) {
        $depth = 0;
        for ($i = $open; $i < $off; $i++) { if ($css[$i] === '{') { $depth++; } elseif ($css[$i] === '}') { $depth--; } }
        $inDark = $depth >= 2;
    }
    if ($inDark) { $rootD = $t + $rootD; } else { $rootL = $t + $rootL; }
}
$pal[$def] = [$rootL, $rootD + $rootL];
foreach (array_slice($keys, 1) as $k) {
    $bl = thBlocks($css, 'html[data-st-palette="' . $k . '"]');
    if (count($bl) !== 2) { $badP[] = "{$k}: " . count($bl) . ' بلوک (باید دو: روز، بعد شب)'; continue; }
    if ($bl[1][1] < (int)$darkAt || $bl[0][1] > $bl[1][1]) { $badP[] = "{$k}: بلوکِ شب داخلِ مدیای شب و بعد از روز نیست"; }
    [$L, $D] = [thTokens($bl[0][0]), thTokens($bl[1][0])];
    foreach ($need as $tk) {
        if (!isset($L[$tk])) { $badP[] = "{$k} (روز): --{$tk}"; }
        if (!isset($D[$tk])) { $badP[] = "{$k} (شب): --{$tk}"; }
    }
    $pal[$k] = [$L, $D + $L];
}
T::bulk(count($keys) - 1, $badP, 'هر رنگ همه‌ی توکن‌هایش را در روز و در شب بازتعریف می‌کند');

preg_match_all('/html\[data-st-palette="([a-z]+)"\]/', $css, $cm);
$orph = array_values(array_diff(array_unique($cm[1]), $keys));
T::bulk(count(array_unique($cm[1])), array_map(fn($x) => "«{$x}» در CSS هست ولی در Biz::PALETTES نه", $orph), 'هیچ رنگِ یتیمی در store.css نیست');

$badS = [];
foreach ($keys as $k) {
    if (!preg_match('/\.st-pal-swatch\[data-pal="' . $k . '"\]\s*\{[^}]*linear-gradient/', $css)) { $badS[] = $k; }
    if (strtolower(Biz::PALETTES[$k]['theme']) !== strtolower($pal[$k][0]['st-rb3'] ?? '')) {
        $badS[] = "{$k}: theme با --st-rb3ِ روز یکی نیست";
    }
}
T::bulk(count($keys), $badS, 'هر رنگ نمونه‌ی طیف‌دار دارد و رنگِ نوارِ وضعیتش همان --st-rb3 است');

// ---------------------------------------------------------------
T::group('۲ — خوانایی: اعداد از خودِ قاعده خوانده می‌شوند، نه کپیِ محلی');
// ---------------------------------------------------------------
$side = thBlocks($css, '.st-side');
$sideG = '';
foreach ($side as [$b]) { if (str_contains($b, 'var(--st-rb2)')) { $sideG = $b; } }
$ok1 = preg_match('/color-mix\(in srgb,\s*var\(--st-rb2\)\s*(\d+)%,\s*var\(--st-rb3\)\)/', $sideG, $mm);
$ok2 = preg_match('/rgba\(0,\s*0,\s*0,\s*(\.\d+)\)/', $sideG, $vm);
$ok3 = preg_match('/\.st-side-item\s*\{[^}]*?(?<![\w-])color:\s*rgba\(255,\s*255,\s*255,\s*(\.\d+)\)/', $css, $tm);
$hero = '';
foreach (thBlocks($css, '.st-kpi-card.st-kpi-hero') as [$b]) { if (str_contains($b, 'var(--st-rb2)')) { $hero = $b; } }
$ok4 = preg_match('/color-mix\(in srgb,\s*var\(--st-rb2\)\s*(\d+)%,\s*var\(--st-rb3\)\)/', $hero, $hm);
T::ok($ok1 && $ok2 && $ok3 && $ok4, 'منو و کارتِ «فروشِ امروز» از --st-rb2/--st-rb3 ساخته می‌شوند');

if ($ok1 && $ok2 && $ok3 && $ok4) {
    $bad = []; $n = 0;
    $surf = ['روز' => [255, 255, 255], 'شب' => thHex('#131418')];
    foreach ($pal as $k => [$L, $D]) {
        foreach (['روز' => $L, 'شب' => $D] as $mode => $T) {
            $n++;
            foreach (['st-rb2', 'st-rb3', 'st-accent', 'st-accent-ink'] as $tk) {
                if (!preg_match('/^#[0-9a-f]{6}$/i', $T[$tk] ?? '')) { $bad[] = "{$k} ({$mode}): --{$tk} رنگِ شش‌رقمی نیست"; continue 2; }
            }
            $bg = thMix([0, 0, 0], thMix(thHex($T['st-rb2']), thHex($T['st-rb3']), (int)$mm[1] / 100), (float)$vm[1]);
            $fg = thMix([255, 255, 255], $bg, (float)$tm[1]);
            if (($r = thCr($fg, $bg)) < 4.5) { $bad[] = sprintf('%s (%s): متنِ منو %.2f', $k, $mode, $r); }
            $hb = thMix(thHex($T['st-rb2']), thHex($T['st-rb3']), (int)$hm[1] / 100);
            if (($r = thCr([255, 255, 255], $hb)) < 4.5) { $bad[] = sprintf('%s (%s): عددِ سفیدِ «فروشِ امروز» %.2f', $k, $mode, $r); }
            if (($r = thCr(thHex($T['st-accent']), $surf[$mode])) < 4.5) { $bad[] = sprintf('%s (%s): لینک/لهجه روی پنل %.2f', $k, $mode, $r); }
            if (($r = thCr(thHex($T['st-accent-ink']), thHex($T['st-accent']))) < 4.5) { $bad[] = sprintf('%s (%s): متنِ دکمه %.2f', $k, $mode, $r); }
        }
    }
    T::bulk($n, $bad, 'متنِ منو، عددِ قهرمان، لینک و دکمه در هر رنگ (روز و شب) دست‌کم ۴٫۵ تضاد دارند');
}

// ---------------------------------------------------------------
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::blocked('رنگِ فروشگاه', 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
    exit(T::report());
}
if (!Biz::available() || !tableHasColumn('biz_settings', 'palette')) {
    T::blocked('رنگِ فروشگاه', 'ستونِ palette نیست — اول: bash deploy/migrate.sh --apply');
    exit(T::report());
}

$wipe = function () use ($pdo) {
    foreach ($pdo->query("SELECT id FROM users WHERE username LIKE '" . TPREFIX . "%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        deleteUserAccount((int)$id);
    }
    $pdo->exec("DELETE FROM login_attempts WHERE username_tried LIKE '" . TPREFIX . "%'");
};
$wipe();
$make = function (string $name) use ($pdo): int {
    $res = createUserAccount($pdo, 'فروشگاهِ ' . $name, TPREFIX . $name, TPREFIX . $name . '@example.com', TPASS);
    if (!($res['ok'] ?? false)) { throw new RuntimeException('ساختِ کاربر: ' . ($res['error'] ?? '?')); }
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => TPREFIX . $name]);
    return (int)$st->fetchColumn();
};
$a = $make('a');
$b = $make('b');
Biz::setType($a, 'business');
Biz::setType($b, 'business');
$raw = function (int $uid) use ($pdo) {
    $st = $pdo->prepare('SELECT palette FROM biz_settings WHERE user_id = :u');
    $st->execute(['u' => $uid]);
    return $st->fetchColumn();
};

T::group('۳ — ذخیره: پیش‌فرض NULL، نامِ ناشناخته رد، هر فروشگاه جدا');
T::same($def, Biz::palette($a), 'فروشگاهِ تازه رنگِ پیش‌فرض دارد');
T::ok(Biz::savePalette($a, 'ocean')['ok'], 'ذخیره‌ی «اقیانوس»');
T::same('ocean', Biz::palette($a), 'palette() همان را می‌خواند (کشِ درخواست تازه شد)');
T::same('ocean', $raw($a), 'در دیتابیس نام نشست');
T::same($def, Biz::palette($b), '⛔ رنگِ فروشگاهِ A روی B ننشست');
$r = Biz::savePalette($a, 'x" onload="alert(1)');
T::ok(!$r['ok'] && Biz::palette($a) === 'ocean', '⛔ نامِ ناشناخته رد شد و رنگِ قبلی ماند');
Biz::savePalette($a, $def);
T::ok($raw($a) === null, '⛔ پیش‌فرض NULL ذخیره شد، نه نامش (پیش‌فرضِ فردا به همه برسد)');
$pdo->prepare('UPDATE biz_settings SET palette = :p WHERE user_id = :u')->execute(['p' => 'neon', 'u' => $a]);
$fresh = (new ReflectionClass('Biz'));
foreach (['settingsCache', 'paletteCache'] as $prop) { $pp = $fresh->getProperty($prop); $pp->setAccessible(true); $pp->setValue(null, []); }
T::same($def, Biz::palette($a), 'مقدارِ ناشناخته در دیتابیس به پیش‌فرض برمی‌گردد');
Biz::savePalette($a, $def);

// ---------------------------------------------------------------
T::group('۴ — با HTTP و نشستِ واقعی');
// ---------------------------------------------------------------
$port = 0;
for ($pp = 18470; $pp < 18490; $pp++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$pp", $e1, $e2);
    if ($sock) { fclose($sock); $port = $pp; break; }
}
$log = tempnam(sys_get_temp_dir(), 'bthm');
$srv = $port ? (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    $port, escapeshellarg($root), escapeshellarg($log)))) : 0;
$up = false;
for ($i = 0; $srv && $i < 40; $i++) {
    usleep(150000);
    $sk = @fsockopen('127.0.0.1', $port, $x, $y, 0.3);
    if ($sk) { fclose($sk); $up = true; break; }
}
if (!$up) {
    T::blocked('رنگِ فروشگاه (HTTP)', 'سرورِ آزمایشی بالا نیامد');
    if ($srv) { exec("kill $srv 2>/dev/null"); }
    $wipe();
    exit(T::report());
}
$jar = tempnam(sys_get_temp_dir(), 'bthmjar');
$req = function (string $path, ?array $post = null) use ($port, $jar): array {
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 40]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};
$csrfOf = fn(string $html): string => preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m) ? $m[1] : '';
[, $lp] = $req('store/login.php');
[$c] = $req('store/login.php', ['csrf_token' => $csrfOf($lp), 'username' => TPREFIX . 'a', 'password' => TPASS]);
T::ok($c === 302, 'ورود به فروشگاه');

[$c, $home] = $req('store/index.php');
T::ok($c === 200 && str_contains($home, '<html lang="fa" dir="rtl">'), 'پیش‌فرض هیچ ویژگی‌ای روی <html> ندارد');
T::ok(str_contains($home, '<meta name="theme-color" content="' . Biz::PALETTES[$def]['theme'] . '">'), 'نوارِ وضعیتِ گوشی رنگِ پیش‌فرض را دارد');

[$c, $set] = $req('store/settings.php');
T::same(count($keys), substr_count($set, 'name="palette"'), 'انتخابگر یک رادیو برای هر رنگ دارد');
T::ok(str_contains($set, 'value="' . $def . '" checked'), 'رنگِ جاری انتخاب‌شده است');
[$c] = $req('store/settings.php', ['action' => 'palette', 'palette' => 'lilac']);
T::ok($c !== 302 && $raw($a) === null, '⛔ بی‌CSRF هیچ چیزی ذخیره نشد');
[$c, $loc] = $req('store/settings.php', ['csrf_token' => $csrfOf($set), 'action' => 'palette', 'palette' => 'lilac']);
T::ok($c === 302, 'ذخیره با CSRF → ریدایرکت');
[$c, $home] = $req('store/index.php');
T::ok(str_contains($home, '<html lang="fa" dir="rtl" data-st-palette="lilac">'), 'همان صفحه‌ی بعد <html> رنگِ «یاس» را دارد (سمتِ سرور، بی‌اسکریپت)');
T::ok(str_contains($home, '<meta name="theme-color" content="' . Biz::PALETTES['lilac']['theme'] . '">'), 'نوارِ وضعیتِ گوشی هم «یاس» شد');
[$c, $set] = $req('store/settings.php');
T::ok(str_contains($set, 'value="lilac" checked'), 'انتخابگر رنگِ ذخیره‌شده را نشان می‌دهد');
[$c] = $req('store/settings.php', ['csrf_token' => $csrfOf($set), 'action' => 'palette', 'palette' => 'neon']);
// ⚠ از دیتابیس، نه `palette()`: کشِ همین پروسه مقدارِ پیش از درخواستِ HTTP را دارد
T::same('lilac', $raw($a), '⛔ رنگِ ناشناخته از فرم هم رد شد');
T::ok($raw($b) === null || $raw($b) === false, '⛔ فروشگاهِ B دست نخورد');
[$c, $set] = $req('store/settings.php');
T::ok(str_contains($set, 'value="lilac" checked') && str_contains($set, 'name="shop_name"'), 'فرمِ سربرگ کنارِ رنگ سرِ جایش است');

exec("kill $srv 2>/dev/null");
@unlink($log); @unlink($jar);
$wipe();
exit(T::report());
