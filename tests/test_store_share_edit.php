<?php
/**
 * سودِ فروشگاه در دفترِ شخصی — اصلاحِ دستیِ ماندگار، و سهمِ خودِ فروشگاه.
 *
 * **خواسته‌ی مالکِ نصب:** «اولندش امکانِ ویرایشِ سود بیاد، ممکنه سود تغییر
 * کرده باشه. دوما … برای خودِ من هم باید سودها و درصدها بیاد … و حتما هم
 * باید قابل تغییر باشه.»
 *
 * چیزهایی که اینجا نگه داشته می‌شود و هیچ‌کدام در بازبینی دیده نمی‌شوند:
 *
 *  ۱. اصلاحِ کاربر با همگام‌سازیِ بعدی **پس گرفته نمی‌شود**؛ فقط عددِ
 *     فروشگاه (`store_share_origin`) کنارش تازه می‌شود.
 *  ۲. «ذخیره»ی بی‌تغییر سطر را از فروشگاه جدا نمی‌کند.
 *  ۳. کلید از `doc` (پایدار) ساخته می‌شود: ثبتِ دوباره‌ی فاکتور در فروشگاه
 *     (`ref` تازه) سطرِ اصلاح‌شده را پاک نمی‌کند.
 *  ۴. حذفِ سطری که فروشگاه هنوز دارد رد می‌شود (برمی‌گشت)؛ سطرِ اصلاح‌شده‌ای
 *     که فروشگاه دیگر ندارد پاک **نمی‌شود** و نشان می‌خورد.
 *  ۵. «عددِ فروشگاه» اصلاح را برمی‌گرداند.
 *  ۶. سهمِ خودِ فروشگاه فقط به دفترِ یک **مدیر**، فقط با تاریخ، با پیشوندِ
 *     همان کاربر؛ پاسخِ بی‌کلید یا با تاریخِ دیگر چیزی را پاک نمی‌کند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

$root = realpath(__DIR__ . '/..');

if (!file_exists($root . '/config/config.php')) {
    T::group('اصلاحِ سودِ فروشگاه');
    T::blocked('تست اصلاحِ سودِ فروشگاه', 'config/config.php وجود ندارد');
    exit(T::report());
}

// ⛔ پیش از کانفیگ — همان دلیلِ `test_store_share.php`.
$port = 0;
for ($p = 9031; $p <= 9059; $p++) {
    $sock = @stream_socket_server("tcp://127.0.0.1:$p", $e1, $e2);
    if ($sock) { fclose($sock); $port = $p; break; }
}
if (!$port) {
    T::group('اصلاحِ سودِ فروشگاه');
    T::skip('تست اصلاحِ سودِ فروشگاه', 'پورت آزاد پیدا نشد');
    exit(T::report());
}

$fakeDir = sys_get_temp_dir() . '/sse_fake_' . getmypid();
@mkdir($fakeDir, 0700, true);
$stateFile = $fakeDir . '/state.json';
$queryFile = $fakeDir . '/query.txt';

// سرورِ ساختگی: پاسخ از فایل، و رشته‌ی پرسشِ آخرین درخواست در فایلِ دیگر
// تا معلوم شود `house_from` واقعاً فرستاده شده.
file_put_contents($fakeDir . '/router.php', <<<'PHPSRV'
<?php
header('Content-Type: application/json; charset=utf-8');
if (!hash_equals('Bearer ' . getenv('SS_TOKEN'), $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    http_response_code(401);
    echo '{"ok":false}';
    return;
}
file_put_contents(getenv('SS_QUERY'), (string)($_SERVER['QUERY_STRING'] ?? ''));
echo (string)file_get_contents(getenv('SS_STATE'));
PHPSRV
);

define('STORE_API_URL', "http://127.0.0.1:{$port}/router.php");
define('STORE_API_TOKEN', 'test-token-edit-0123456789abcdef');

require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/user_data.php';
require_once $root . '/includes/signup.php';
require_once $root . '/includes/store_share.php';
require_once $root . '/includes/transactions.php';

$pdo = Database::getConnection();

if (!StoreShare::available() || !tableHasColumn('transactions', 'store_share_ref')) {
    T::group('اصلاحِ سودِ فروشگاه');
    T::skip('تست اصلاحِ سودِ فروشگاه', 'migration_store_share.sql هنوز اجرا نشده');
    exit(T::report());
}
if (!StoreShare::editAvailable()) {
    T::group('اصلاحِ سودِ فروشگاه');
    T::skip('تست اصلاحِ سودِ فروشگاه', 'migration_store_share_edit.sql هنوز اجرا نشده');
    exit(T::report());
}
if (!str_contains((string)STORE_API_URL, (string)$port)) {
    T::group('اصلاحِ سودِ فروشگاه');
    T::skip('تست اصلاحِ سودِ فروشگاه', 'این نصب خودش STORE_API_URL دارد؛ تست به آن نمی‌خورد');
    exit(T::report());
}

$srvLog = tempnam(sys_get_temp_dir(), 'ssefake');
$pid = (int)trim((string)shell_exec(sprintf(
    'SS_TOKEN=%s SS_STATE=%s SS_QUERY=%s php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    escapeshellarg(STORE_API_TOKEN), escapeshellarg($stateFile), escapeshellarg($queryFile),
    $port, escapeshellarg($fakeDir), escapeshellarg($srvLog))));

$setState = function (array $data) use ($stateFile) {
    file_put_contents($stateFile, json_encode($data, JSON_UNESCAPED_UNICODE));
};
$setState(['ok' => true, 'shareholders' => []]);

$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(150000);
    $s = @fsockopen('127.0.0.1', $port, $a1, $a2, 0.3);
    if ($s) { fclose($s); $up = true; break; }
}

// تنظیمِ صاحبِ فروشگاهِ همین نصب دست‌نخورده برمی‌گردد
$savedHouseUser = getSetting(StoreShare::HOUSE_USER_KEY, '');
$savedHouseFrom = getSetting(StoreShare::HOUSE_FROM_KEY, '');

register_shutdown_function(function () use ($pid, $fakeDir) {
    if ($pid > 0) { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
    foreach (glob($fakeDir . '/*') as $f) { @unlink($f); }
    @rmdir($fakeDir);
});

if (!$up) {
    T::group('اصلاحِ سودِ فروشگاه');
    T::ok(false, 'سرورِ ساختگی بالا آمد', substr((string)@file_get_contents($srvLog), 0, 300));
    exit(T::report());
}

$purge = function (string $u) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $u]);
    $id = $st->fetchColumn();
    if (!$id) { return; }
    foreach ([userDataTables(), array_reverse(userDataTables())] as $tables) {
        foreach ($tables as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { }
        }
    }
    try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); }
    catch (PDOException $e) { }
};

$names = ['__sse_sh__' => 'user', '__sse_boss__' => 'admin', '__sse_boss2__' => 'admin', '__sse_plain__' => 'user'];
foreach (array_keys($names) as $n) { $purge($n); }
$pdo->exec('DELETE FROM store_shareholders WHERE store_contact_id BETWEEN 900101 AND 900199');

$uid = [];
foreach ($names as $n => $role) {
    $res = createUserAccount($pdo, 'آزمون ' . $n, $n, $n . '@t.local', 'StoreEdit1234', $role);
    if (empty($res['ok']) || empty($res['id'])) {
        T::group('اصلاحِ سودِ فروشگاه');
        T::blocked('تست اصلاحِ سودِ فروشگاه', 'ساخت کاربر آزمایشی نشد: ' . (string)($res['error'] ?? ''));
        exit(T::report());
    }
    $uid[$n] = (int)$res['id'];
}
$S     = $uid['__sse_sh__'];
$BOSS  = $uid['__sse_boss__'];
$BOSS2 = $uid['__sse_boss2__'];
$PLAIN = $uid['__sse_plain__'];

$row = function (int $u, string $ref) use ($pdo): ?array {
    $st = $pdo->prepare(
        'SELECT id, type, amount, title, note, transaction_date, store_share_origin, store_share_edited
         FROM transactions WHERE user_id = :u AND store_share_ref = :r'
    );
    $st->execute(['u' => $u, 'r' => $ref]);
    $r = $st->fetch();
    return $r ?: null;
};
$count = function (int $u, string $pre) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND store_share_ref LIKE :p');
    $st->execute(['u' => $u, 'p' => $pre . '%']);
    return (int)$st->fetchColumn();
};

$shareholder = static fn(array $shares) => [
    'id' => 900101, 'name' => 'سهامدار آزمون', 'capital' => 0, 'earned' => 0, 'paid' => 0, 'balance' => 0,
    'devices' => [], 'items' => [], 'shares' => $shares,
];
$payload = static fn(array $shares, ?array $house = null) => array_filter([
    'ok' => true, 'store' => ['net_worth' => 1, 'shareholders_owed' => 0],
    'shareholders' => [$shareholder($shares)],
    'house' => $house,
], static fn($v) => $v !== null);

/* ─────────────── ۱. کلیدِ پایدار ─────────────── */

T::group('۱ — کلیدِ پایدار از `doc`');

$setState($payload([
    ['ref' => 'jl:10', 'doc' => 'SALE_INVOICE:77', 'date' => '2026-09-20', 'amount' => 4000000,
     'description' => 'سود فروش آیفون ۱۳ — فاکتور ۷۷'],
]));
$link = StoreShare::link($S, 900101, 'سهامدار آزمون');
T::ok(!empty($link['ok']), 'وصل شد', (string)$link['message']);
StoreShare::sync();

$K = 'store:900101:d:SALE_INVOICE:77';
$r = $row($S, $K);
T::ok($r !== null && (int)$r['amount'] === 4000000, 'سطر با کلیدِ مدرک ثبت شد', var_export($r, true));
T::ok($r !== null && (int)$r['store_share_origin'] === 4000000 && (int)$r['store_share_edited'] === 0,
    'عددِ فروشگاه کنارش نوشته شد و «اصلاح‌شده» نیست');

// ثبتِ دوباره‌ی همان فاکتور در فروشگاه: `ref` تازه، `doc` همان
$setState($payload([
    ['ref' => 'jl:55', 'doc' => 'SALE_INVOICE:77', 'date' => '2026-09-20', 'amount' => 4000000,
     'description' => 'سود فروش آیفون ۱۳ — فاکتور ۷۷'],
]));
StoreShare::sync();
$r2 = $row($S, $K);
T::ok($r2 !== null && (int)$r2['id'] === (int)$r['id'] && $count($S, 'store:900101:') === 1,
    '⛔ ثبتِ دوباره‌ی فاکتور همان ردیف می‌ماند، نه پاک و از نو');

/* ─────────────── ۲. اصلاحِ ماندگار ─────────────── */

T::group('۲ — اصلاحِ کاربر با همگام‌سازی پس گرفته نمی‌شود');

$id = (int)$r2['id'];
$catIncome = $pdo->query("SELECT id FROM categories WHERE name = '" . StoreShare::CATEGORY_INCOME . "' AND user_id IS NULL LIMIT 1")->fetchColumn();

// «ذخیره»ی بی‌تغییر
$same = txUpdate($S, $id, [
    'type' => 'income', 'amount' => '4000000', 'title' => $r2['title'], 'note' => (string)$r2['note'],
    'transaction_date' => $r2['transaction_date'], 'category_id' => (string)$catIncome,
]);
T::ok(!empty($same['ok']), 'ذخیره‌ی بی‌تغییر انجام شد', (string)($same['message'] ?? ''));
T::ok((int)$row($S, $K)['store_share_edited'] === 0, '⛔ ذخیره‌ی بی‌تغییر سطر را «اصلاح‌شده» نمی‌کند');

$upd = txUpdate($S, $id, [
    'type' => 'income', 'amount' => '4500000', 'title' => 'سود آیفون — توافقی', 'note' => '',
    'transaction_date' => '2026-09-21', 'category_id' => (string)$catIncome,
]);
T::ok(!empty($upd['ok']), 'ویرایش انجام شد');
T::ok((int)$row($S, $K)['store_share_edited'] === 1, 'سطر «اصلاح‌شده» علامت خورد');

// فروشگاه عددش را عوض می‌کند
$setState($payload([
    ['ref' => 'jl:56', 'doc' => 'SALE_INVOICE:77', 'date' => '2026-09-20', 'amount' => 4200000,
     'description' => 'سود فروش آیفون ۱۳ — فاکتور ۷۷'],
]));
StoreShare::sync();
$r3 = $row($S, $K);
T::ok((int)$r3['amount'] === 4500000 && $r3['title'] === 'سود آیفون — توافقی' && $r3['transaction_date'] === '2026-09-21',
    '⛔ مبلغ، عنوان و تاریخِ اصلاح‌شده سرِ جایشان ماندند', var_export($r3, true));
T::ok((int)$r3['store_share_origin'] === 4200000, 'و عددِ تازه‌ی فروشگاه کنارش آمد');

/* ─────────────── ۳. حذف ─────────────── */

T::group('۳ — حذف: سطرِ زنده رد، سطرِ رفته آزاد');

$del = txDelete($S, $id);
T::ok(empty($del['ok']) && (int)($del['status'] ?? 0) === 409,
    '⛔ حذفِ سودی که فروشگاه هنوز دارد رد می‌شود (برمی‌گشت)', (string)($del['message'] ?? ''));

// یک سطرِ اصلاح‌نشده‌ی دوم، و بعد فروشگاه هر دو را حذف می‌کند
$setState($payload([
    ['ref' => 'jl:56', 'doc' => 'SALE_INVOICE:77', 'date' => '2026-09-20', 'amount' => 4200000, 'description' => 'الف'],
    ['ref' => 'jl:57', 'doc' => 'SALE_INVOICE:78', 'date' => '2026-09-22', 'amount' => 1000000, 'description' => 'ب'],
]));
StoreShare::sync();
T::ok($row($S, 'store:900101:d:SALE_INVOICE:78') !== null, 'سطرِ دوم آمد');

$setState($payload([]));
StoreShare::sync();
T::ok($row($S, 'store:900101:d:SALE_INVOICE:78') === null, 'سطرِ اصلاح‌نشده‌ی بی‌مرجع رفت');
$gone = $row($S, $K);
T::ok($gone !== null && $gone['store_share_origin'] === null && (int)$gone['amount'] === 4500000,
    '⛔ سطرِ اصلاح‌شده پاک نشد — داده‌ی کاربر است — و «در فروشگاه نیست» نشان خورد', var_export($gone, true));

$rev = StoreShare::revertEdit($S, $id);
T::ok(empty($rev['ok']), 'برگرداندنِ سطری که فروشگاه ندارد ممکن نیست', (string)$rev['message']);

/* ─────────────── ۴. برگرداندن ─────────────── */

T::group('۴ — «عددِ فروشگاه» اصلاح را برمی‌گرداند');

$setState($payload([
    ['ref' => 'jl:60', 'doc' => 'SALE_INVOICE:77', 'date' => '2026-09-20', 'amount' => 4200000,
     'description' => 'سود فروش آیفون ۱۳ — فاکتور ۷۷'],
]));
StoreShare::sync();
T::ok((int)$row($S, $K)['store_share_origin'] === 4200000, 'سطر دوباره در فروشگاه است');

$other = StoreShare::revertEdit($PLAIN, $id);
T::ok(empty($other['ok']) && (int)$row($S, $K)['store_share_edited'] === 1,
    '⛔ کاربرِ دیگر نمی‌تواند سطرِ این کاربر را برگرداند');

$rev = StoreShare::revertEdit($S, $id);
T::ok(!empty($rev['ok']), 'برگرداندن انجام شد', (string)$rev['message']);
$r4 = $row($S, $K);
T::ok((int)$r4['amount'] === 4200000 && (int)$r4['store_share_edited'] === 0, 'مبلغ همان عددِ فروشگاه شد');
StoreShare::sync();
$r5 = $row($S, $K);
T::ok($r5['title'] === 'سود فروش آیفون ۱۳ — فاکتور ۷۷' && $r5['transaction_date'] === '2026-09-20',
    'و همگام‌سازیِ بعدی عنوان و تاریخ را هم از فروشگاه آورد');

/* ─────────────── ۵. ردیفِ تراکنش ─────────────── */

T::group('۵ — ردیفِ تراکنش: نشان، دکمه‌ی برگرداندن، بی‌حذف');

$render = function (int $u, int $txId) use ($pdo): string {
    $st = $pdo->prepare(
        'SELECT t.id, t.type, t.amount, t.title, t.note, t.transaction_date, t.category_id,
                NULL AS category_name, NULL AS cat_icon, NULL AS cat_color' . txStoreShareCols() . '
         FROM transactions t WHERE t.user_id = :u AND t.id = :id'
    );
    $st->execute(['u' => $u, 'id' => $txId]);
    ob_start();
    renderTransactionRow($st->fetch());
    return (string)ob_get_clean();
};
txUpdate($S, $id, ['type' => 'income', 'amount' => '4600000', 'title' => 'x', 'note' => '',
    'transaction_date' => '2026-09-20', 'category_id' => (string)$catIncome]);
$html = $render($S, $id);
T::ok(str_contains($html, 'js-store-revert') && str_contains($html, 'سهامِ فروشگاه'),
    'سطرِ اصلاح‌شده دکمه‌ی «عددِ فروشگاه» دارد');
T::ok(!str_contains($html, 'js-delete-tx'), '⛔ و دکمه‌ی حذف ندارد');
T::ok(str_contains($html, 'data-store="1"'), 'ویرایش، راهنمای سودِ فروشگاه را باز می‌کند');

$plainTx = txCreate($PLAIN, ['type' => 'expense', 'amount' => '1000', 'title' => 'نان',
    'transaction_date' => '2026-09-20', 'category_id' => '']);
$plainHtml = $render($PLAIN, (int)$plainTx['id']);
T::ok(str_contains($plainHtml, 'js-delete-tx') && !str_contains($plainHtml, 'js-store-revert')
    && !str_contains($plainHtml, 'سهامِ فروشگاه'),
    'تراکنشِ عادی دست‌نخورده: حذف دارد، نشانِ فروشگاه ندارد');

/* ─────────────── ۶. سهمِ خودِ فروشگاه ─────────────── */

T::group('۶ — سهمِ خودِ فروشگاه در دفترِ مدیر');

$bad = StoreShare::setHouse($PLAIN, '2026-09-01');
T::ok(empty($bad['ok']), '⛔ کاربرِ عادی صاحبِ فروشگاه نمی‌شود', (string)$bad['message']);
$bad2 = StoreShare::setHouse($BOSS, 'دیروز');
T::ok(empty($bad2['ok']), '⛔ بی‌تاریخِ معتبر پذیرفته نمی‌شود');

$houseLines = [
    ['ref' => 'inv:501', 'date' => '2026-09-10', 'amount' => 6000000, 'profit' => 10000000, 'percent' => 60,
     'category' => 'PHONE', 'category_label' => 'گوشی', 'description' => 'سود فروش آیفون ۱۴ — فاکتور ۵۰۱'],
    ['ref' => 'inv:502', 'date' => '2026-09-11', 'amount' => 400000, 'profit' => 400000, 'percent' => 100,
     'category' => 'ACCESSORY', 'category_label' => 'لوازم جانبی', 'description' => 'سود فروش گارد — فاکتور ۵۰۲'],
    ['ref' => 'ms:ACCESSORY_SHARE:140506', 'date' => '2026-09-21', 'amount' => -40000, 'profit' => null,
     'percent' => null, 'category' => 'ACCESSORY', 'category_label' => 'لوازم جانبی',
     'description' => 'سهمِ سهامداران از سهم لوازم جانبی 1405/06'],
];
$houseBlock = ['id' => null, 'name' => 'فروشگاه', 'shares_from' => '2026-09-01', 'shares' => $houseLines];
$setState($payload([], $houseBlock));

$ok = StoreShare::setHouse($BOSS, '2026-09-01');
T::ok(!empty($ok['ok']), 'مدیر صاحبِ فروشگاه شد', (string)$ok['message']);
StoreShare::sync();

T::ok(str_contains((string)@file_get_contents($queryFile), 'house_from=2026-09-01'),
    '⛔ تاریخِ شروع به فروشگاه فرستاده شد', (string)@file_get_contents($queryFile));

$HP = StoreShare::housePrefix($BOSS);
$h1 = $row($BOSS, $HP . 'inv:501');
T::ok($h1 !== null && (int)$h1['amount'] === 6000000 && $h1['type'] === 'income',
    'سهمِ فروشگاه از فروشِ گوشی در دفترِ مدیر نشست', var_export($h1, true));
T::ok($h1 !== null && str_contains((string)$h1['note'], '۶۰٪') && str_contains((string)$h1['note'], 'سود کل'),
    'سود و درصد در توضیح آمده', (string)($h1['note'] ?? ''));
$h3 = $row($BOSS, $HP . 'ms:ACCESSORY_SHARE:140506');
T::ok($h3 !== null && $h3['type'] === 'expense' && (int)$h3['amount'] === 40000,
    'کسرِ ماهانه‌ی سهامداران هزینه ثبت شد');
$wallet = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND store_share_ref LIKE :p AND wallet_id IS NOT NULL');
$wallet->execute(['u' => $BOSS, 'p' => $HP . '%']);
T::ok((int)$wallet->fetchColumn() === 0, '⛔ بی‌حساب — موجودیِ حساب‌ها تکان نمی‌خورد');

// اصلاحِ مدیر روی سهمِ فروشگاه هم ماندگار است
$hid = (int)$h1['id'];
txUpdate($BOSS, $hid, ['type' => 'income', 'amount' => '5800000', 'title' => (string)$h1['title'],
    'note' => (string)$h1['note'], 'transaction_date' => '2026-09-10', 'category_id' => (string)$catIncome]);
$houseLines[0]['amount'] = 6100000;
$setState($payload([], ['shares_from' => '2026-09-01', 'shares' => $houseLines] + $houseBlock));
StoreShare::sync();
$h1b = $row($BOSS, $HP . 'inv:501');
T::ok((int)$h1b['amount'] === 5800000 && (int)$h1b['store_share_origin'] === 6100000,
    '⛔ اصلاحِ مدیر ماند و عددِ تازه‌ی فروشگاه کنارش آمد');

// پاسخِ بی‌کلید یا با تاریخِ دیگر چیزی را پاک نمی‌کند
$before = $count($BOSS, $HP);
$setState($payload([], ['id' => null, 'name' => 'فروشگاه']));
StoreShare::sync();
T::ok($count($BOSS, $HP) === $before, '⛔ پاسخِ بی‌`shares` (فروشگاهِ عقب‌مانده) چیزی را پاک نکرد');
$setState($payload([], ['shares_from' => '2026-08-01', 'shares' => []] + $houseBlock));
StoreShare::sync();
T::ok($count($BOSS, $HP) === $before, '⛔ پاسخی که با تاریخِ دیگری ساخته شده چیزی را پاک نکرد');

// سطرِ اصلاح‌نشده‌ای که فروشگاه دیگر نمی‌فرستد می‌رود
$setState($payload([], ['shares_from' => '2026-09-01', 'shares' => [$houseLines[0]]] + $houseBlock));
StoreShare::sync();
T::ok($row($BOSS, $HP . 'inv:502') === null && $row($BOSS, $HP . 'inv:501') !== null,
    'هم‌گام: سطرِ رفته برداشته شد، سطرِ اصلاح‌شده ماند');

// عوض کردنِ صاحبِ فروشگاه: دفترِ تازه پر می‌شود، دفترِ قبلی دست‌نخورده
$setState($payload([], $houseBlock));
StoreShare::setHouse($BOSS2, '2026-09-01');
StoreShare::sync();
T::ok($count($BOSS2, StoreShare::housePrefix($BOSS2)) === 3,
    '⛔ مدیرِ تازه سطرهای خودش را گرفت (پیشوندِ جدا، نه به‌روز شدنِ ردیفِ قبلی)');
T::ok($count($BOSS, $HP) >= 1, 'و سطرهای مدیرِ قبلی پاک نشدند');

$off = StoreShare::setHouse(0, '');
T::ok(!empty($off['ok']) && StoreShare::house() === null, 'خاموش شد');

/* ─────────────── پاک‌سازی ─────────────── */

setSetting(StoreShare::HOUSE_USER_KEY, $savedHouseUser === '' ? '0' : $savedHouseUser);
if ($savedHouseFrom !== '') { setSetting(StoreShare::HOUSE_FROM_KEY, $savedHouseFrom); }
foreach (array_keys($names) as $n) { $purge($n); }
$pdo->exec('DELETE FROM store_shareholders WHERE store_contact_id BETWEEN 900101 AND 900199');
$pdo->exec('UPDATE store_sync SET payload = NULL, fetched_at = NULL, last_error = NULL WHERE id = 1');

exit(T::report());
