<?php
/**
 * تقویمِ خانه — از زبانه‌ی «سررسیدها» به خانه.
 *
 * **خواسته‌ی مالکِ نصب:** «تقویم رو از داخل یادآوری من بیار بیرون یه جای
 * خوشگل توی داشبورد چه در گوشی و چه در دسکتاپ براش بساز از جای قبلی
 * پاکش کن.»
 *
 * سه لایه:
 *   ۱. توابعِ خالص (بی‌دیتابیس): ماه، آدرس، شبکه‌ی روزها (روزِ اولِ هفته،
 *      اسفندِ کبیسه، جمعه، ردیفِ هفته‌ی امروز).
 *   ۲. داده: نقطه‌ها و جمع‌ها از تراکنش و `financialEvents()`، و جداییِ
 *      کاربران.
 *   ۳. HTTP: پوسته روی خانه (گوشی و دسکتاپ)، اندپوینت، هدایتِ آدرس‌های
 *      قدیمی، و نبودنِ زبانه در `due.php`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';
$root = realpath(__DIR__ . '/..');
// ⚠ `home_calendar.php` بی‌`APP_BASE_PATH` بی‌صدا ۴۰۴ می‌دهد و خارج می‌شود
//   (نگهبانِ partial) — پس بی‌کانفیگ «مسدود»، نه یک اجرای خالیِ سبز.
if (!file_exists($root . '/config/config.php')) { T::blocked('تقویمِ خانه', 'config/config.php وجود ندارد'); exit(T::report()); }
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/home_calendar.php';

/** دکمه‌های روز: [class, date, inner] */
$cells = function (string $html): array {
    preg_match_all('~<button type="button" class="(hcal-d[^"]*)" data-date="([0-9-]+)"[^>]*>(.*?)</button>~s', $html, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [$x[1], $x[2], $x[3]], $m);
};
$has = fn(string $cls, string $c): bool => in_array($c, explode(' ', $cls), true);

// ---------------------------------------------------------------
T::group('تقویمِ خانه — ماه و آدرس');
[$ty, $tm] = homeCalendarToday();
T::same([$ty, $tm], homeCalendarMonth(0, 0), 'بی‌پارامتر = ماهِ امروز');
T::same([1405, 1], homeCalendarMonth(1404, 13), 'ماهِ ۱۳ = فروردینِ سالِ بعد');
T::same([1403, 12], homeCalendarMonth(1404, 0), 'ماهِ ۰ = اسفندِ سالِ قبل');
T::same([$ty, $tm], homeCalendarMonth(1999, 5), 'سالِ بیرون از بازه = ماهِ امروز');
T::same(APP_BASE_PATH . '/index.php#cal', homeCalendarUrl(), 'آدرسِ ماهِ جاری بی‌پارامتر است');
T::same(APP_BASE_PATH . '/index.php#cal', homeCalendarUrl($ty, $tm), 'ماهِ جاری حتی با پارامتر هم بی‌پارامتر');
T::same(APP_BASE_PATH . '/index.php?jy=1403&jm=12#cal', homeCalendarUrl(1403, 12), 'ماهِ دیگر با jy/jm');

// ---------------------------------------------------------------
T::group('تقویمِ خانه — شبکه‌ی روزها');
// ۱ فروردین ۱۴۰۵ = ۲۱ مارس ۲۰۲۶، شنبه → بی‌خانه‌ی خالیِ اول
$far = homeCalendarHtml(1405, 1, null);
$c = $cells($far);
T::same(31, count($c), 'فروردین ۳۱ دکمه‌ی روز دارد');
T::same('2026-03-21', $c[0][1], 'روزِ اول = ۲۱ مارس');
$beforeFirst = explode('<button', explode('<div class="hcal-grid">', $far)[1] ?? '')[0];
T::ok(strpos($beforeFirst, 'is-adj') === false, 'شنبه است، پس پیش از روزِ اول هیچ روزِ ماهِ قبل نیست');
$esf = homeCalendarHtml(1404, 12, null);   // ۱ اسفند ۱۴۰۴ = ۲۰ فوریه ۲۰۲۶، جمعه → شش خانه‌ی ماهِ قبل
$beforeFirst = explode('<button', explode('<div class="hcal-grid">', $esf)[1] ?? '')[0];
T::same(6, substr_count($beforeFirst, 'is-adj'), 'اسفند ۱۴۰۴ از جمعه شروع می‌شود: شش روزِ بهمن پیش از آن');
T::ok(str_contains($beforeFirst, '>۲۵<') && str_contains($beforeFirst, '>۳۰<'), 'و همان ۲۵ تا ۳۰ِ بهمن‌اند');
T::same(30, count($cells(homeCalendarHtml(1403, 12, null))), 'اسفندِ ۱۴۰۳ (کبیسه) ۳۰ روز');
T::same(29, count($cells(homeCalendarHtml(1404, 12, null))), 'اسفندِ ۱۴۰۴ ۲۹ روز');

// جمعه‌ها: هر دکمه‌ی is-fri واقعاً جمعه است و هر جمعه is-fri دارد
$friBad = [];
foreach ([[1405, 1], [1404, 12], [$ty, $tm]] as [$y, $mo]) {
    foreach ($cells(homeCalendarHtml($y, $mo, null)) as [$cls, $d]) {
        $isFri = (int)date('w', strtotime($d)) === 5;
        if ($isFri !== $has($cls, 'is-fri')) { $friBad[] = "{$y}/{$mo} {$d}"; }
    }
}
T::same([], $friBad, 'is-fri دقیقاً روی جمعه‌هاست');

// هر ماه ضربِ ۷ خانه دارد (روزهای ماهِ قبل/بعد ردیف را پر می‌کنند)
$mar = homeCalendarHtml(1404, 12, null);
$adj = preg_match_all('~class="hcal-d is-adj~', $mar);
T::same(0, (count($cells($mar)) + $adj) % 7, 'خانه‌ها ضربِ ۷ (ردیفِ آخر هم کامل)');

// امروز و هفته‌اش
$now = homeCalendarHtml($ty, $tm, null);
$todayCells = array_values(array_filter($cells($now), fn($x) => $has($x[0], 'is-today')));
T::ok(count($todayCells) === 1 && $todayCells[0][1] === today(), 'ماهِ جاری: دقیقاً یک «امروز» و همان today()');
T::same(7, preg_match_all('~class="hcal-d[^"]*\bis-wk\b~', $now), 'نوارِ هفته دقیقاً هفت خانه');
$wkDates = array_column(array_filter($cells($now), fn($x) => $has($x[0], 'is-wk')), 1);
T::ok(in_array(today(), $wkDates, true), 'و امروز در همان هفته است');
T::ok(strpos($now, 'data-now="1"') !== false && strpos($now, 'hcal-now') === false, 'ماهِ جاری: دکمه‌ی «امروز» ندارد');
T::ok(strpos($mar, 'data-now="0"') !== false && strpos($mar, 'class="hcal-now') !== false, 'ماهِ دیگر: دکمه‌ی «امروز» دارد');
T::same(0, count(array_filter($cells($mar), fn($x) => $has($x[0], 'is-today'))), 'ماهِ دیگر هیچ «امروز»ی ندارد');
T::ok(strpos($now, 'data-pending="1"') !== false && strpos($now, 'hcal-dot is-') === false, 'پوسته: بی‌نقطه و منتظرِ داده');
T::ok(strpos($far, 'data-jy="1404" data-jm="12"') !== false && strpos($far, 'data-jy="1405" data-jm="2"') !== false,
    'ماهِ قبل/بعدِ فروردین = اسفندِ سالِ قبل و اردیبهشت');

// ---------------------------------------------------------------
T::group('تقویمِ خانه — داده');
require_once $root . '/includes/user_data.php';
try { $pdo = Database::getConnection(); }
catch (Throwable $e) { T::blocked('تقویمِ خانه', 'اتصال به دیتابیس برقرار نشد'); exit(T::report()); }

$USER  = ['home_cal_u', 'HomeCal#9'];
$OTHER = ['home_cal_o', 'HomeCal#9'];
$serverPid = 0;
$jars = [];
$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if (!$id) { return; }
    try { deleteUserAccount($id); } catch (Throwable $e) {}
    try { $pdo->prepare('DELETE FROM users WHERE id = :i')->execute(['i' => $id]); } catch (Throwable $e) {}
};
$cleanup = function () use (&$serverPid, $purge, $USER, $OTHER, &$jars) {
    if ($serverPid) { @exec("kill $serverPid 2>/dev/null"); $serverPid = 0; }
    try { $purge($USER[0]); $purge($OTHER[0]); } catch (Throwable $e) {}
    foreach ($jars as $j) { @unlink($j); }
};

try {
    $mk = function (array $u) use ($pdo, $purge): int {
        $purge($u[0]);
        $pdo->prepare("INSERT INTO users (full_name, username, password_hash, role, is_active)
                       VALUES ('کاربر تقویم', :u, :p, 'user', 1)")
            ->execute(['u' => $u[0], 'p' => password_hash($u[1], PASSWORD_DEFAULT)]);
        $id = (int)$pdo->lastInsertId();
        try { $pdo->prepare('UPDATE users SET balance_setup_at = NOW() WHERE id = :u')->execute(['u' => $id]); } catch (Throwable $e) {}
        return $id;
    };
    $uid = $mk($USER);
    $oid = $mk($OTHER);

    $g = fn(int $y, int $m, int $d): string => vsprintf('%04d-%02d-%02d', jalaliToGregorian($y, $m, $d));
    $dIn = $g(1404, 12, 5); $dOut = $g(1404, 12, 9); $dDebt = $g(1404, 12, 20);
    $tx = $pdo->prepare('INSERT INTO transactions (user_id, type, amount, title, transaction_date) VALUES (:u, :t, :a, :ti, :d)');
    $tx->execute(['u' => $uid, 't' => 'income',  'a' => 1200000, 'ti' => 'حقوق', 'd' => $dIn]);
    $tx->execute(['u' => $uid, 't' => 'income',  'a' => 300000,  'ti' => 'هدیه', 'd' => $dIn]);
    $tx->execute(['u' => $uid, 't' => 'expense', 'a' => 450000,  'ti' => 'خرید', 'd' => $dOut]);
    // ⛔ کاربرِ دیگر، همان روز — نه نقطه، نه جمع
    $tx->execute(['u' => $oid, 't' => 'expense', 'a' => 99000000, 'ti' => 'دیگری', 'd' => $dIn]);
    $tx->execute(['u' => $oid, 't' => 'income',  'a' => 77000000, 'ti' => 'دیگری', 'd' => $g(1404, 12, 12)]);
    // بدهیِ گذشته‌ی تسویه‌نشده → سررسیدِ گذشته
    $pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
                   VALUES (:u, 'payable', 'تقویم‌آزمون', 500000, :e, :d)")
        ->execute(['u' => $uid, 'e' => $g(1404, 12, 1), 'd' => $dDebt]);

    $data = homeCalendarData($uid, 1404, 12);
    T::same(1500000, $data['in'], 'جمعِ دریافتیِ ماه (دو تراکنشِ یک روز)');
    T::same(450000, $data['out'], 'جمعِ پرداختیِ ماه');
    T::same(1, $data['dues'], 'یک سررسید در ماه');
    T::same(1, $data['late'], 'و گذشته است');
    T::ok(!isset($data['days'][$g(1404, 12, 12)]), '⛔ روزی که فقط کاربرِ دیگر دارد نقطه نمی‌گیرد');

    $html = homeCalendarHtml(1404, 12, $data);
    $byDate = [];
    foreach ($cells($html) as [$cls, $d, $inner]) { $byDate[$d] = [$cls, $inner]; }
    T::ok(str_contains($byDate[$dIn][1], 'hcal-dot is-in') && !str_contains($byDate[$dIn][1], 'is-out'),
        'روزِ دریافت فقط نقطه‌ی سبز (پرداختِ کاربرِ دیگر همان روز دیده نمی‌شود)');
    T::ok(str_contains($byDate[$dOut][1], 'hcal-dot is-out') && !str_contains($byDate[$dOut][1], 'is-in'), 'روزِ پرداخت فقط نقطه‌ی قرمز');
    T::ok(str_contains($byDate[$dDebt][1], 'hcal-dot is-late') && str_contains($byDate[$dDebt][1], 'hcal-dot is-out'),
        'روزِ بدهیِ گذشته: پرداخت + سررسیدِ گذشته');
    T::ok($has($byDate[$dIn][0], 'has-any') && !$has($byDate[$g(1404, 12, 2)][0], 'has-any'), 'فقط روزِ دارای چیزی زمینه می‌گیرد');
    T::ok(str_contains($html, formatMoney(1500000)) && str_contains($html, formatMoney(450000)), 'جمع‌ها روی کارت');
    T::ok(str_contains($html, 'سررسیدِ گذشته') && str_contains($html, 'due.php?t=list&amp;f=overdue'), 'چیپِ سررسیدِ گذشته به همان صافی می‌رود');
    T::ok(!str_contains($html, 'data-pending'), 'با داده دیگر «منتظر» نیست');

    // سررسیدِ آینده (نه گذشته) → نقطه‌ی طلایی، نه نارنجی
    [$ny, $nm] = homeCalendarMonth($ty, $tm + 1);
    $dFut = $g($ny, $nm, 10);
    $pdo->prepare("INSERT INTO debts (user_id, direction, counterparty_name, amount, entry_date, due_date)
                   VALUES (:u, 'receivable', 'آینده‌آزمون', 200000, :e, :d)")
        ->execute(['u' => $uid, 'e' => today(), 'd' => $dFut]);
    $fut = homeCalendarHtml($ny, $nm, homeCalendarData($uid, $ny, $nm));
    $fd = array_values(array_filter($cells($fut), fn($x) => $x[1] === $dFut));
    T::ok($fd && str_contains($fd[0][2], 'is-due') && str_contains($fd[0][2], 'is-in') && !str_contains($fd[0][2], 'is-late'),
        'طلبِ آینده: دریافت + سررسید (طلایی)، نه «گذشته»');
    T::ok(str_contains($fut, '۱ سررسید') && !str_contains($fut, 'سررسیدِ گذشته'), 'چیپِ «۱ سررسید»');

    // ---------------------------------------------------------------
    T::group('تقویمِ خانه — HTTP');
    $port = 0;
    for ($p = 8971; $p <= 8989; $p++) {
        $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
        if ($sock) { fclose($sock); $port = $p; break; }
    }
    if (!$port) { T::skip('تقویمِ خانه', 'پورت آزاد پیدا نشد'); $cleanup(); exit(T::report()); }
    $log = tempnam(sys_get_temp_dir(), 'hcsrv');
    $serverPid = (int)trim((string)shell_exec(sprintf('php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
        $port, escapeshellarg($root), escapeshellarg($log))));
    $up = false;
    for ($i = 0; $i < 40; $i++) {
        usleep(150000);
        $s = @fsockopen('127.0.0.1', $port, $a, $b, 0.3);
        if ($s) { fclose($s); $up = true; break; }
    }
    if (!$up) { T::blocked('تقویمِ خانه', 'سرور آزمایشی بالا نیامد'); $cleanup(); exit(T::report()); }

    $jar = tempnam(sys_get_temp_dir(), 'hcjar'); $jars[] = $jar;
    $req = function (string $path, ?array $post = null, string $cookie = '') use ($port, $jar): array {
        $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_HEADER => true]);
        if ($cookie !== '') { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw  = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $loc = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $lm) ? $lm[1] : '';
        return [$code, substr($raw, $hs), $loc];
    };
    $pdo->exec("DELETE FROM login_attempts WHERE request_ip = '127.0.0.1'");
    [, $html] = $req('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    $req('login.php', ['csrf_token' => $m[1] ?? '', 'username' => $USER[0], 'password' => $USER[1]]);

    // خانه‌ی گوشی
    [$code, $home] = $req('index.php');
    T::same(200, $code, 'خانه باز می‌شود');
    T::same(1, substr_count($home, 'id="cal"'), 'دقیقاً یک تقویم');
    T::ok(str_contains($home, '<section class="card hcal is-week" id="cal"'), 'گوشی: نوارِ هفته');
    T::ok(str_contains($home, 'data-pending="1"') && !str_contains($home, 'hcal-dot is-'), '⛔ خانه داده‌ی تقویم را خودش نمی‌خواند (پوسته، بی‌کوئری)');
    T::ok(preg_match('~id="cal".*?آخرین تراکنش‌های من~s', $home) === 1, 'جایش بالای «آخرین تراکنش‌ها»');
    T::ok(str_contains($home, 'class="home-date" href="' . APP_BASE_PATH . '/index.php#cal"'), 'تاریخِ بالای خانه به تقویم می‌رود');

    // ماهِ دیگر از آدرس
    [, $homeM] = $req('index.php?jy=1404&jm=12');
    T::ok(str_contains($homeM, 'اسفند ۱۴۰۴') && str_contains($homeM, 'data-now="0"'), 'پوسته‌ی ماهِ دیگر از ?jy=&jm=');

    // اندپوینت
    [$code, $frag] = $req('api/home_calendar.php?jy=1404&jm=12');
    T::same(200, $code, 'اندپوینت ۲۰۰');
    T::ok(str_starts_with(trim($frag), '<div class="hcal-inner"') && !str_contains($frag, '<html'), 'فقط درونِ کارت، نه صفحه');
    T::ok(str_contains($frag, formatMoney(1500000)) && str_contains($frag, 'hcal-dot is-late'), 'داده‌ی همین کاربر');
    T::ok(!str_contains($frag, formatMoney(99000000)) && !str_contains($frag, formatMoney(77000000)), '⛔ نه داده‌ی کاربرِ دیگر');
    [, $fragNow] = $req('api/home_calendar.php');
    T::ok(str_contains($fragNow, 'data-now="1"'), 'بی‌پارامتر = ماهِ جاری');
    [, $fragBad] = $req('api/home_calendar.php?jy=abc&jm=99');
    T::ok(str_contains($fragBad, 'data-now="1"') || str_contains($fragBad, 'hcal-inner'), 'ورودیِ خراب = ماهِ معتبر، نه خطا');

    // آدرس‌های قدیمی
    [$code, , $loc] = $req('calendar.php?jy=1404&jm=12');
    T::ok($code === 301 && str_ends_with($loc, '/index.php?jy=1404&jm=12#cal'), 'calendar.php → تقویمِ خانه با همان ماه', "{$code} {$loc}");
    [$code, , $loc] = $req('due.php?t=calendar');
    T::ok($code === 301 && str_ends_with($loc, '/index.php#cal'), '⛔ due.php?t=calendar → تقویمِ خانه (نه بی‌صدا «سررسیدها»)', "{$code} {$loc}");
    [$code, , $loc] = $req('due.php?t=calendar&jy=1404&jm=12');
    T::ok($code === 301 && str_ends_with($loc, '/index.php?jy=1404&jm=12#cal'), 'و ماه را نگه می‌دارد', "{$code} {$loc}");
    [$code, $due] = $req('due.php');
    T::ok($code === 200 && !str_contains($due, 't=calendar') && !str_contains($due, '>تقویم<'), '⛔ «سررسیدها» دیگر زبانه‌ی تقویم ندارد');
    T::same(2, preg_match_all('~<a href="\?t=[a-z]+" class="page-tab ~', $due), 'دو زبانه مانده: سررسیدها و یادآورهای من');

    // دسکتاپ
    [, $desk] = $req('index.php', null, DESK_COOKIE . '=1');
    $side = preg_match('/<aside class="home-side desk-only">(.*?)<\/aside>/s', $desk, $sm) ? $sm[1] : '';
    T::same(1, substr_count($desk, 'id="cal"'), 'دسکتاپ: دقیقاً یک تقویم');
    T::ok(str_contains($side, '<section class="card hcal is-desk" id="cal"'), 'دسکتاپ: بالای ستونِ کناری، ماهِ کامل');
    T::ok(strpos($side, 'id="cal"') < strpos($side, 'مانده‌ی حساب‌ها'), 'و پیش از «مانده‌ی حساب‌ها»');

    // خاموش از پروفایل
    saveHomeHidden($uid, ['calendar']);
    [, $off] = $req('index.php');
    T::ok(!str_contains($off, 'class="card hcal') && !str_contains($off, 'id="cal"'), 'قلمِ خاموش: تقویم رندر نمی‌شود');
    T::ok(str_contains($off, 'class="home-date" href="' . APP_BASE_PATH . '/due.php?t=list"'), 'و تاریخِ بالا به «سررسیدها» می‌رود، نه به لنگرِ ناموجود');
    saveHomeHidden($uid, []);
} finally {
    $cleanup();
}

exit(T::report());
