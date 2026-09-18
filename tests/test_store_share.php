<?php
/**
 * سهمِ سهامدارِ فروشگاه — رفتار.
 *
 * ⛔ **چرا با یک سرورِ ساختگی و نه با mock:** خودِ `StoreShare::fetch()`
 *    یک درخواستِ واقعیِ HTTP با هدرِ توکن است و نیمی از خرابی‌های
 *    محتملش همان‌جاست (پاسخِ HTML به‌جای JSON، `ok = false`، بدنه‌ی
 *    بی‌`shareholders`). با جایگزین کردنِ آن تابع، تستی می‌ساختیم که
 *    نسخه‌ی دومِ خودش را می‌سنجد — همان دامی که یک بار بخشِ CSV در
 *    `test_tx_search` در آن افتاد.
 *
 * ⛔ **و چرا پیلودِ ساختگی، نه حسابداریِ واقعی:** آن سیستم روی این
 *    ماشین نیست و اگر بود هم تست به دادِه‌ی زنده بند می‌شد. پیلود
 *    **دقیقاً شکلِ قراردادِ** `api/shareholding` است؛ اگر آن قرارداد
 *    عوض شود، این فایل باید همان روز عوض شود.
 *
 * چیزهایی که این فایل می‌سنجد و هیچ‌کدام در بازبینی دیده نمی‌شوند:
 *
 *  ۱. تراکنشِ سود **بدون حساب** ثبت می‌شود → موجودیِ حساب‌ها تکان
 *     نمی‌خورد. با `wallet_id`، همان پول دو بار شمرده می‌شد.
 *  ۲. همگام‌سازیِ دوباره **ردیفِ تازه نمی‌سازد** (ایدمپوتنسی).
 *  ۳. مبلغِ اصلاح‌شده در سمتِ فروشگاه، ردیفِ همین‌جا را **به‌روز**
 *     می‌کند، نه ردیفِ دومی بسازد.
 *  ۴. سطری که در سمتِ فروشگاه حذف شده، از دفترِ کاربر هم می‌رود —
 *     وگرنه یک درآمدِ یتیم برای همیشه می‌ماند.
 *  ۵. `forUser()` **هر دو** شرط را می‌خواهد: پیوندِ فعال و ردیفِ آینه.
 *  ۶. سهمِ منفی (زیان) به‌عنوان **هزینه** ثبت می‌شود.
 *  ۷. سقفِ پنج نفر در مسیرِ **نوشتن** اعمال می‌شود.
 *  ۸. پاسخِ خراب آینه‌ی سالمِ قبلی را **پاک نمی‌کند**.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('سهم سهامدار فروشگاه');
    T::blocked('تست سهم سهامدار', 'config/config.php وجود ندارد');
    exit(T::report());
}

/*
 * ⛔ این دو **پیش از** لود شدنِ کانفیگ تعریف می‌شوند.
 *
 * `StoreShare::configured()` از `defined()` می‌خواند و ثابت یک بار
 * تعریف می‌شود. روی نصبی که خودش این دو را در `config.php` دارد،
 * `define()`ِ ما بی‌اثر است و تست به همان آدرسِ واقعی می‌خورد — که
 * غلط است، پس در آن حالت رد می‌شویم (پایین‌تر سنجیده می‌شود).
 */
$port = 0;
for ($p = 8971; $p <= 8999; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}

if (!$port) {
    T::group('سهم سهامدار فروشگاه');
    T::skip('تست سهم سهامدار', 'پورت آزاد پیدا نشد');
    exit(T::report());
}

$fakeDir = sys_get_temp_dir() . '/ss_fake_' . getmypid();
@mkdir($fakeDir, 0700, true);
$stateFile = $fakeDir . '/state.json';

// سرورِ ساختگی: پاسخ را از یک فایل می‌خواند، پس تست بینِ دو
// همگام‌سازی می‌تواند «سمتِ فروشگاه» را عوض کند.
file_put_contents($fakeDir . '/router.php', <<<'PHPSRV'
<?php
header('Content-Type: application/json; charset=utf-8');
$want = 'Bearer ' . getenv('SS_TOKEN');
$got  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!hash_equals($want, $got)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'توکن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    return;
}
$state = getenv('SS_STATE');
echo (string)file_get_contents($state);
PHPSRV
);

define('STORE_API_URL', "http://127.0.0.1:{$port}/router.php");
define('STORE_API_TOKEN', 'test-token-0123456789abcdef');

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/signup.php';
require_once $root . '/includes/store_share.php';

$pdo = Database::getConnection();

if (!tableExists('store_shareholders') || !tableExists('store_sync')
    || !tableHasColumn('transactions', 'store_share_ref')) {
    T::group('سهم سهامدار فروشگاه');
    T::skip('تست سهم سهامدار', 'migration_store_share.sql هنوز اجرا نشده');
    exit(T::report());
}

if (!StoreShare::configured() || !str_contains((string)STORE_API_URL, (string)$port)) {
    T::group('سهم سهامدار فروشگاه');
    T::skip('تست سهم سهامدار', 'این نصب خودش STORE_API_URL دارد؛ تست به آن نمی‌خورد');
    exit(T::report());
}

/* ─────────────── سرورِ ساختگی ─────────────── */

$srvLog = tempnam(sys_get_temp_dir(), 'ssfake');
$pid = (int)trim((string)shell_exec(sprintf(
    'SS_TOKEN=%s SS_STATE=%s php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    escapeshellarg(STORE_API_TOKEN), escapeshellarg($stateFile),
    $port, escapeshellarg($fakeDir), escapeshellarg($srvLog))));

$setState = function (array $data) use ($stateFile) {
    file_put_contents($stateFile, json_encode($data, JSON_UNESCAPED_UNICODE));
};

// وضعیتِ اولیه، وگرنه سرور فایلِ نبوده را می‌خواند
$setState(['ok' => true, 'shareholders' => []]);

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a1, $a2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}

register_shutdown_function(function () use ($pid, $fakeDir) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    foreach (glob($fakeDir . '/*') as $f) { @unlink($f); }
    @rmdir($fakeDir);
});

if (!$up) {
    T::group('سهم سهامدار فروشگاه');
    T::ok(false, 'سرورِ ساختگی بالا آمد', substr((string)@file_get_contents($srvLog), 0, 300));
    exit(T::report());
}

/* ─────────────── کاربرانِ آزمایشی ─────────────── */

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    // ⛔ فهرستِ جدول‌ها از دیتابیس کشف می‌شود، نه دستی — همان درسِ
    //    `test_api_auth`: جدولِ تازه‌ای که جا بماند کلیدِ خارجی را نگه
    //    می‌دارد و ساختِ کاربر شکست می‌خورد، و آن‌وقت کلِ فایل با
    //    پیامِ گمراه‌کننده رد می‌شود.
    foreach (userDataTables() as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
        catch (PDOException $e) { /* ترتیبِ کلید خارجی — دورِ بعد */ }
    }
    foreach (array_reverse(userDataTables()) as $t) {
        try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
        catch (PDOException $e) { }
    }
    try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); }
    catch (PDOException $e) { }
};

$names = ['__ss_a__', '__ss_b__', '__ss_c__', '__ss_d__', '__ss_e__', '__ss_f__', '__ss_g__'];
foreach ($names as $n) { $purge($n); }
$pdo->exec('DELETE FROM store_shareholders WHERE store_contact_id BETWEEN 900001 AND 900099');

$uid = [];
foreach ($names as $i => $n) {
    $res = createUserAccount($pdo, 'سهامدار ' . $i, $n, $n . '@t.local',
        'StorePass' . $i . '234', 'user');
    if (empty($res['ok']) || empty($res['id'])) {
        T::group('سهم سهامدار فروشگاه');
        T::blocked('تست سهم سهامدار', 'ساخت کاربر آزمایشی نشد: ' . (string)($res['error'] ?? ''));
        exit(T::report());
    }
    $uid[$n] = (int)$res['id'];
}

$A = $uid['__ss_a__'];
$B = $uid['__ss_b__'];

$txOf = function (int $u) use ($pdo): array {
    $st = $pdo->prepare(
        'SELECT store_share_ref, type, amount, title, transaction_date, wallet_id
         FROM transactions WHERE user_id = :u AND store_share_ref IS NOT NULL
         ORDER BY store_share_ref'
    );
    $st->execute(['u' => $u]);
    return $st->fetchAll();
};

/* ─────────────── ۱. پیوند و اولین همگام‌سازی ─────────────── */

T::group('۱ — وصل کردن و اولین همگام‌سازی');

$payload1 = ['ok' => true, 'generated_at' => '2026-09-18T00:00:00Z',
    'store' => ['net_worth' => 4500000000, 'assets' => 9000000000, 'liabilities' => 4500000000],
    'shareholders' => [[
        'id' => 900001, 'name' => 'علی اصغر', 'capital' => 453000000,
        'earned' => 4800000, 'paid' => 0, 'balance' => 457800000,
        'devices' => [
            ['id' => 11, 'imei' => '350000000000011', 'product' => 'آیفون ۱۳',
             'status' => 'IN_STOCK', 'condition' => 'USED', 'cost' => 151000000,
             'sale_price' => 0, 'purchased_at' => '2026-08-01', 'sold_at' => null],
            ['id' => 12, 'imei' => '350000000000012', 'product' => 'آیفون ۱۴',
             'status' => 'SOLD', 'condition' => 'USED', 'cost' => 63000000,
             'sale_price' => 75000000, 'purchased_at' => '2026-08-02', 'sold_at' => '2026-09-01'],
        ],
        'shares' => [
            ['ref' => 'jl:5001', 'date' => '2026-09-01', 'amount' => 4800000,
             'description' => 'سهم سهامدار از سود فاکتور ۱۲'],
        ],
    ]],
];
$setState($payload1);

$link = StoreShare::link($A, 900001, 'علی اصغر');
T::ok(!empty($link['ok']), 'وصل کردن انجام شد', (string)$link['message']);

$sync = StoreShare::sync();
T::ok(!empty($sync['ok']), 'همگام‌سازی موفق بود', (string)$sync['message']);
T::ok($sync['written'] === 1, 'یک ردیفِ سود ثبت شد', 'written=' . $sync['written']);

$sh = StoreShare::forUser($A);
T::ok(is_array($sh) && (int)$sh['id'] === 900001, 'forUser() ردیفِ همان سهامدار را می‌دهد');
T::ok(StoreShare::valueFor($A) === 457800000, 'ارزشِ دارایی همان «مانده»ی دفترِ فروشگاه است',
    'value=' . var_export(StoreShare::valueFor($A), true));
T::ok(StoreShare::storeNetWorth() === 4500000000, 'خالصِ کلِ فروشگاه خوانده می‌شود');

$rows = $txOf($A);
T::ok(count($rows) === 1, 'دقیقاً یک تراکنشِ سود در دفترِ کاربر است', 'n=' . count($rows));
T::ok(($rows[0]['type'] ?? '') === 'income', 'سودِ مثبت درآمد ثبت شده');
T::ok((int)($rows[0]['amount'] ?? 0) === 4800000, 'مبلغ همان سهمِ سود است');
T::ok(($rows[0]['transaction_date'] ?? '') === '2026-09-01',
    '⛔ تاریخ، تاریخِ سندِ فروشگاه است نه روزِ همگام‌سازی');

/*
 * ⛔ مهم‌ترین بررسیِ کلِ فایل: بدونِ حساب.
 *
 * دارایی سهامدار خودش یک قلمِ «خالص دارایی» است؛ اگر سود به حسابی هم
 * می‌نشست همان پول دو بار شمرده می‌شد — همان قاعده‌ی
 * `syncTradeProfitTransactions()`.
 */
$walletNull = !array_key_exists('wallet_id', $rows[0]) || $rows[0]['wallet_id'] === null;
T::ok($walletNull, '⛔ تراکنشِ سود هیچ حسابی ندارد',
    'wallet_id=' . var_export($rows[0]['wallet_id'] ?? '(no column)', true));

$before = totalBalance($A);
StoreShare::sync();
T::ok(totalBalance($A) === $before,
    '⛔ موجودیِ حساب‌ها با ثبتِ سود تکان نمی‌خورد');

/* ─────────────── ۲. ایدمپوتنسی ─────────────── */

T::group('۲ — همگام‌سازیِ دوباره ردیف تکراری نمی‌سازد');

$s2 = StoreShare::sync();
T::ok(!empty($s2['ok']), 'همگام‌سازیِ دوم موفق بود');
T::ok(count($txOf($A)) === 1, '⛔ باز هم یک ردیف — نه دو تا', 'n=' . count($txOf($A)));
T::ok($s2['removed'] === 0, 'چیزی بی‌دلیل حذف نشد', 'removed=' . $s2['removed']);

/* ─────────────── ۳. مبلغِ اصلاح‌شده به‌روز می‌شود ─────────────── */

T::group('۳ — مبلغِ عوض‌شده به‌روز می‌شود، نه ردیفِ دوم');

$payload2 = $payload1;
$payload2['shareholders'][0]['shares'][0]['amount'] = 5200000;
$payload2['shareholders'][0]['earned']              = 5200000;
$payload2['shareholders'][0]['balance']             = 458200000;
$setState($payload2);

StoreShare::sync();
$rows = $txOf($A);
T::ok(count($rows) === 1, 'همچنان یک ردیف', 'n=' . count($rows));
T::ok((int)$rows[0]['amount'] === 5200000, 'مبلغ به‌روز شد', 'amount=' . $rows[0]['amount']);
T::ok(StoreShare::valueFor($A) === 458200000, 'مانده‌ی تازه خوانده می‌شود');

/* ─────────────── ۴. سطرِ حذف‌شده در سمتِ فروشگاه ─────────────── */

T::group('۴ — سطرِ بی‌مرجع از دفترِ کاربر می‌رود');

$payload3 = $payload2;
$payload3['shareholders'][0]['shares'] = [
    ['ref' => 'jl:5099', 'date' => '2026-09-05', 'amount' => 3000000,
     'description' => 'سهم سهامدار از سود فاکتور ۱۳'],
];
$setState($payload3);

$s4 = StoreShare::sync();
$rows = $txOf($A);
T::ok(count($rows) === 1, 'یک ردیف مانده', 'n=' . count($rows));
T::ok($rows[0]['store_share_ref'] === 'store:900001:jl:5099',
    '⛔ ردیفِ تازه نشسته و قدیمی رفته', (string)$rows[0]['store_share_ref']);
T::ok($s4['removed'] === 1, 'حذفِ سطرِ یتیم گزارش شد', 'removed=' . $s4['removed']);

/* ─────────────── ۵. سهمِ منفی هزینه است ─────────────── */

T::group('۵ — سهمِ زیان هزینه ثبت می‌شود');

$payload4 = $payload3;
$payload4['shareholders'][0]['shares'][] = [
    'ref' => 'jl:5100', 'date' => '2026-09-06', 'amount' => -800000,
    'description' => 'سهم سهامدار از سود فاکتور ۱۴',
];
$setState($payload4);
StoreShare::sync();

$rows = $txOf($A);
$neg  = null;
foreach ($rows as $r) { if ($r['store_share_ref'] === 'store:900001:jl:5100') { $neg = $r; } }
T::ok($neg !== null, 'ردیفِ زیان ثبت شد');
T::ok($neg !== null && $neg['type'] === 'expense', '⛔ زیان هزینه است نه درآمد',
    'type=' . ($neg['type'] ?? '—'));
T::ok($neg !== null && (int)$neg['amount'] === 800000,
    'مبلغ بی‌علامت ذخیره می‌شود (قاعده‌ی ستونِ amount)');

/* ─────────────── ۶. forUser() هر دو شرط را می‌خواهد ─────────────── */

T::group('۶ — هیچ‌کدام از دو شرط به‌تنهایی کافی نیست');

T::ok(StoreShare::forUser($B) === null,
    '⛔ کاربرِ بی‌پیوند هیچ چیزی نمی‌بیند، هرچند آینه پر است');

$pdo->prepare('UPDATE store_shareholders SET is_active = 0 WHERE user_id = :u')
    ->execute(['u' => $A]);
T::ok(StoreShare::forUser($A) === null,
    '⛔ پیوندِ غیرفعال یعنی هیچ داده‌ای، هرچند آینه سرِ جایش است');
T::ok(StoreShare::valueFor($A) === null, 'valueFor() هم همان را می‌گوید');

// ⛔ و تراکنش‌های ثبت‌شده دست‌نخورده می‌مانند: آن پول واقعاً رسیده و
//    دفترِ خودِ کاربر است. قطعِ دسترسی با پاک کردنِ تاریخچه یکی نیست.
T::ok(count($txOf($A)) === 2, '⛔ قطعِ پیوند تراکنش‌های قبلی را پاک نمی‌کند',
    'n=' . count($txOf($A)));

$pdo->prepare('UPDATE store_shareholders SET is_active = 1 WHERE user_id = :u')
    ->execute(['u' => $A]);

// پیوندی که شناسه‌اش در آینه نیست هم چیزی نمی‌دهد
$lk = StoreShare::link($B, 900077, 'کسی که در فروشگاه نیست');
T::ok(!empty($lk['ok']), 'پیوندِ دومی ساخته شد');
T::ok(StoreShare::forUser($B) === null,
    '⛔ پیوندِ فعال بدونِ ردیفِ آینه هم هیچ چیزی نمی‌دهد');

/* ─────────────── ۷. سقفِ پنج نفر در مسیرِ نوشتن ─────────────── */

T::group('۷ — سقفِ پنج نفر');

T::ok(StoreShare::MAX_LINKS === 5, 'سقف پنج است (خواسته‌ی صریحِ مالکِ نصب)');

$ok = 0;
$fail = '';
foreach (['__ss_c__', '__ss_d__', '__ss_e__', '__ss_f__', '__ss_g__'] as $i => $n) {
    $r = StoreShare::link($uid[$n], 900010 + $i, 'س ' . $i);
    if (!empty($r['ok'])) { $ok++; } elseif ($fail === '') { $fail = (string)$r['message']; }
}
T::ok(StoreShare::activeCount() === 5, '⛔ بیش از پنج پیوندِ فعال ساخته نشد',
    'active=' . StoreShare::activeCount());
T::ok($ok === 3, 'سه تای اول پذیرفته شدند و بقیه رد', 'ok=' . $ok);
T::ok(str_contains($fail, 'سقف'), '⛔ پیامِ رد، علتش را می‌گوید', $fail);

/* ─────────────── ۸. پاسخِ خراب آینه را پاک نمی‌کند ─────────────── */

T::group('۸ — پاسخِ خراب، آینه‌ی سالم را نمی‌برد');

$goodValue = StoreShare::valueFor($A);

file_put_contents($stateFile, '<html>۵۰۲ Bad Gateway</html>');
$bad = StoreShare::sync();
T::ok(empty($bad['ok']), 'پاسخِ غیر-JSON رد شد', (string)$bad['message']);
T::ok(str_contains((string)$bad['message'], 'JSON'),
    'پیام می‌گوید چه چیزی خراب بود', (string)$bad['message']);

// کشِ درخواستی را دور می‌زنیم، وگرنه چیزی که در حافظه است سنجیده
// می‌شود نه چیزی که در دیتابیس نشسته.
$fresh = (int)round((float)json_decode(
    (string)$pdo->query('SELECT payload FROM store_sync WHERE id = 1')->fetchColumn(),
    true)['shareholders'][0]['balance']);
T::ok($fresh === $goodValue, '⛔ آینه‌ی قبلی سرِ جایش ماند', 'mirror=' . $fresh);

$st = StoreShare::status();
T::ok(!empty($st['last_error']), 'خطای آخرین تلاش ثبت شد');
T::ok(!empty($st['fetched_at']), 'مهرِ آخرین موفقیت پاک نشد');

$setState(['ok' => false, 'error' => 'توکن تنظیم نشده است.']);
$bad2 = StoreShare::sync();
T::ok(empty($bad2['ok']), '`ok = false` هم رد می‌شود (۲۰۰ گرفتن کافی نیست)');

$setState(['ok' => true]);
$bad3 = StoreShare::sync();
T::ok(empty($bad3['ok']), 'بدنه‌ی بی‌`shareholders` هم رد می‌شود');

/* ─────────────── ۹. توکنِ غلط ─────────────── */

T::group('۹ — توکن');

$setState($payload4);
// سرور با توکنِ دیگری بالا آمده؟ نه — همان توکن است، پس باید موفق شود.
T::ok(!empty(StoreShare::sync()['ok']), 'با توکنِ درست موفق می‌شود');

/* ─────────────── پاک‌سازی ─────────────── */

foreach ($names as $n) { $purge($n); }
$pdo->exec('DELETE FROM store_shareholders WHERE store_contact_id BETWEEN 900001 AND 900099');
$pdo->exec("UPDATE store_sync SET payload = NULL, fetched_at = NULL, last_error = NULL WHERE id = 1");

exit(T::report());
