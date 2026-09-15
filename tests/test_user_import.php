<?php
/**
 * تستِ بازگرداندنِ فایلِ بکاپِ کاربر.
 *
 * ⛔ **چرا این تست از خودِ قابلیت هم مهم‌تر است:** تا امروز فایلِ
 *    `.sthesab` هیچ خواننده‌ای نداشت، یعنی ادعای «بکاپ دارید» هرگز
 *    سنجیده نشده بود. حالا که خواننده دارد، همان ادعا فقط وقتی درست
 *    است که **رفت‌وبرگشتِ واقعی** سنجیده شود — نه اینکه تابع بدونِ خطا
 *    اجرا شود. «بدون خطا اجرا شد» با «کار کرد» یکی نیست.
 *
 * ⛔ و دومین خطرِ این قابلیت امنیتی است، نه رفتاری: فایل **دستِ کاربر**
 *    است و می‌تواند دست‌کاری شود. سه چیز باید ثابت شوند و هر سه اینجا
 *    سنجیده می‌شوند:
 *      ۱. `profile` هرگز نوشته نمی‌شود (وگرنه `"role":"admin"` کافی بود)
 *      ۲. `user_id` همیشه از نشست بازنویسی می‌شود
 *      ۳. جدول‌های اعتبارنامه و `payments` وارد نمی‌شوند
 *
 * ⚠ این تست کاربرِ خودش را می‌سازد و پاک می‌کند. کاربرِ دومی هم می‌سازد
 *   تا ثابت شود بازگرداندنِ یک نفر به دفترِ دیگری دست نمی‌زند.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('بازگرداندن بکاپ');
    T::blocked('تست بازگرداندن', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';
require_once __DIR__ . '/../includes/user_import.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('بازگرداندن بکاپ');
    T::blocked('تست بازگرداندن', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ===============================================================
T::group('کشفِ پیوندها از خودِ دیتابیس');

$fk = importForeignKeys();

T::same('categories', $fk['transactions']['category_id'] ?? null,
    'پیوندِ دسته‌بندیِ تراکنش کشف شد');
T::same('wallets', $fk['transactions']['wallet_id'] ?? null,
    'پیوندِ حسابِ تراکنش کشف شد');
T::same('debts', $fk['debt_payments']['debt_id'] ?? null,
    'پیوندِ پرداختِ بدهی کشف شد');
T::same('trades', $fk['trade_sales']['trade_id'] ?? null,
    'پیوندِ فروشِ معامله کشف شد');

// ⛔ `users` باید بیرون باشد: ستونِ `user_id` مالکیت است و از نشست
//    نوشته می‌شود، نه نگاشت.
$toUsers = [];
foreach ($fk as $t => $cols) {
    foreach ($cols as $c => $p) {
        if ($p === 'users') { $toUsers[] = "$t.$c"; }
    }
}
T::bulk(1, $toUsers, '⛔ هیچ پیوندی به users در نگاشت نیست');

// ===============================================================
T::group('ترتیبِ درج: پدر پیش از فرزند');

$order = importTableOrder(importableTables(), $fk);
$pos   = array_flip($order);

$pairs = [
    ['wallets', 'transactions'],
    ['categories', 'transactions'],
    ['debts', 'debt_payments'],
    ['trades', 'trade_sales'],
    ['savings_goals', 'savings_entries'],
    ['asset_types', 'assets'],
    ['reminders', 'reminder_occurrences'],
    ['transactions', 'attachments'],
];
$badOrder = [];
foreach ($pairs as [$parent, $child]) {
    if (!isset($pos[$parent], $pos[$child])) { continue; }
    if ($pos[$parent] > $pos[$child]) { $badOrder[] = "$child پیش از $parent"; }
}
T::bulk(count($pairs), $badOrder, 'هر پدر پیش از فرزندش می‌آید');

// ===============================================================
T::group('فهرستِ جدول‌های وارداتی');

$imp = importableTables();
$leak = array_values(array_intersect(USER_IMPORT_SKIP, $imp));
T::bulk(count(USER_IMPORT_SKIP), $leak,
    '⛔ اعتبارنامه و پرداخت در فهرستِ واردات نیستند');
T::ok(in_array('transactions', $imp, true), 'تراکنش در فهرستِ واردات هست');
T::ok(in_array('wallets', $imp, true), 'حساب در فهرستِ واردات هست');

// ===============================================================
T::group('فایلِ خراب همان اول رد می‌شود');

$bad = [
    ''                      => 'فایلِ خالی',
    'سلام'                  => 'متنِ غیرِ JSON',
    '[1,2,3]'               => 'آرایه‌ی ساده',
    '{"hello":1}'           => 'JSON بدونِ tables',
    '{"tables":{"x":"y"}}'  => 'tables با محتوای غیرِ آرایه',
];
$accepted = [];
foreach ($bad as $raw => $label) {
    $r = parseBackupFile((string)$raw);
    if (!empty($r['ok'])) { $accepted[] = $label; }
}
T::bulk(count($bad), $accepted, '⛔ هیچ فایلِ خرابی پذیرفته نشد');

$r = parseBackupFile('{"tables":{"transactions":[]}}');
T::ok(!empty($r['ok']), 'فایلِ سالم پذیرفته می‌شود');

// ===============================================================
// ---------- ساختِ کاربرِ آزمایشی ----------
$MINE  = '__test_import_a';
$OTHER = '__test_import_b';

$purge = function (string $username) use ($pdo) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        // چند دور، چون ترتیبِ کلیدهای خارجی از پیش معلوم نیست
        for ($pass = 0; $pass < 4; $pass++) {
            foreach (array_reverse(userDataTables()) as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
                catch (PDOException $e) { /* دورِ بعد */ }
            }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $username]);
};

$cleanup = function () use ($purge, $MINE, $OTHER) {
    try { $purge($MINE); $purge($OTHER); } catch (Throwable $e) { /* ignore */ }
};
$cleanup();
register_shutdown_function($cleanup);

$makeUser = function (string $username) use ($pdo): int {
    $pdo->prepare(
        "INSERT INTO users (full_name, username, password_hash, role, is_active)
         VALUES ('کاربر تست بازگرداندن', :u, :p, 'user', 1)"
    )->execute(['u' => $username, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};

$mineId  = $makeUser($MINE);
$otherId = $makeUser($OTHER);

$seeded = 0;
$ins = function (string $sql, array $args) use ($pdo, &$seeded): int {
    try {
        $pdo->prepare($sql)->execute($args);
        $seeded++;
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) { return 0; }
};

$today  = date('Y-m-d');
$catOut = (int)$pdo->query("SELECT id FROM categories WHERE type='expense' AND is_active=1 AND user_id IS NULL LIMIT 1")->fetchColumn();

$seed = function (int $uid) use ($ins, $today, $catOut, $pdo): array {
    $w1 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                VALUES (:u,'کیف پول','cash',9000000,1,0)", ['u' => $uid]);
    $w2 = $ins("INSERT INTO wallets (user_id,name,kind,initial_balance,is_active,sort_order)
                VALUES (:u,'بانک ملت','bank',4000000,1,1)", ['u' => $uid]);

    // دسته‌ی **شخصی** — این یکی در فایل می‌آید و باید نگاشت شود
    $myCat = $ins("INSERT INTO categories (user_id,name,type,is_active)
                   VALUES (:u,'دسته‌ی شخصیِ تست','expense',1)", ['u' => $uid]);

    $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
          VALUES (:u,'expense',150000,'خریدِ شخصی',:d,:c,:w)",
        ['u' => $uid, 'd' => $today, 'c' => $myCat ?: null, 'w' => $w1]);

    // و یکی با دسته‌ی **پیش‌فرضِ برنامه** — در فایل نیست و باید با نام پیدا شود
    $ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,category_id,wallet_id)
          VALUES (:u,'expense',250000,'خریدِ عمومی',:d,:c,:w)",
        ['u' => $uid, 'd' => $today, 'c' => $catOut ?: null, 'w' => $w2]);

    $ins("INSERT INTO transfers (user_id,from_wallet_id,to_wallet_id,amount,transfer_date)
          VALUES (:u,:a,:b,500000,:d)", ['u' => $uid, 'a' => $w1, 'b' => $w2, 'd' => $today]);
    $ins("INSERT INTO people (user_id,name) VALUES (:u,'علی رضایی')", ['u' => $uid]);

    $d1 = $ins("INSERT INTO debts (user_id,direction,counterparty_name,amount,entry_date,due_date)
                VALUES (:u,'payable','علی رضایی',6000000,:d,:e)",
        ['u' => $uid, 'd' => $today, 'e' => date('Y-m-d', strtotime('+20 day'))]);
    if ($d1) {
        $ins("INSERT INTO debt_payments (user_id,debt_id,amount,payment_date,wallet_id)
              VALUES (:u,:d,1000000,:t,:w)", ['u' => $uid, 'd' => $d1, 't' => $today, 'w' => $w1]);
    }

    $ins("INSERT INTO cheques (user_id,direction,counterparty_name,amount,due_date)
          VALUES (:u,'received','شرکت الف',5000000,:e)",
        ['u' => $uid, 'e' => date('Y-m-d', strtotime('+10 day'))]);

    $at = $ins("INSERT INTO asset_types (user_id,name) VALUES (:u,'سکه')", ['u' => $uid]);
    if ($at) {
        $ins("INSERT INTO assets (user_id,asset_type_id,quantity,entry_date,unit_price)
              VALUES (:u,:t,3,:d,45000000)", ['u' => $uid, 't' => $at, 'd' => $today]);
    }

    $tr = $ins("INSERT INTO trades (user_id,title,buy_total,buy_date,qty)
                VALUES (:u,'دلار',5000000,:d,10)", ['u' => $uid, 'd' => $today]);
    if ($tr) {
        $ins("INSERT INTO trade_sales (trade_id,user_id,qty,sale_total,sale_date)
              VALUES (:t,:u,4,2400000,:d)", ['t' => $tr, 'u' => $uid, 'd' => $today]);
    }

    $ins("INSERT INTO budgets (user_id,category_id,amount) VALUES (:u,:c,4000000)",
        ['u' => $uid, 'c' => $catOut]);
    $g = $ins("INSERT INTO savings_goals (user_id,title,target_amount)
               VALUES (:u,'سفر',20000000)", ['u' => $uid]);
    if ($g) {
        $ins("INSERT INTO savings_entries (goal_id,user_id,amount,entry_date)
              VALUES (:g,:u,3000000,:d)", ['g' => $g, 'u' => $uid, 'd' => $today]);
    }
    $ins("INSERT INTO reminders (user_id,title,remind_date) VALUES (:u,'بیمه خودرو',:d)",
        ['u' => $uid, 'd' => $today]);
    $ins("INSERT INTO recurring_transactions
            (user_id,type,amount,title,category_id,wallet_id,frequency,start_date,next_due_date)
          VALUES (:u,'expense',900000,'اجاره',:c,:w,'monthly',:d,:n)",
        ['u' => $uid, 'c' => $catOut, 'w' => $w1, 'd' => $today,
         'n' => date('Y-m-d', strtotime('+7 day'))]);

    return ['w1' => $w1, 'w2' => $w2, 'cat' => $myCat, 'debt' => $d1, 'trade' => $tr];
};

$ids      = $seed($mineId);
$otherIds = $seed($otherId);

// ⛔ شمارشِ کل کافی نیست — هر جدول جدا. همان درسِ fixtureِ
//    `test_page_render` که یک نامِ ستونِ غلط را بی‌صدا رد کرد.
$needTables = ['wallets', 'transactions', 'transfers', 'debts', 'debt_payments',
               'cheques', 'assets', 'trades', 'budgets', 'savings_goals',
               'savings_entries', 'reminders', 'recurring_transactions',
               'people', 'categories'];
$emptyT = [];
foreach ($needTables as $t) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id = :u");
    $st->execute(['u' => $mineId]);
    if ((int)$st->fetchColumn() === 0) { $emptyT[] = $t; }
}
T::group('آماده‌سازیِ داده‌ی آزمایشی');
T::bulk(count($needTables), $emptyT, 'هر جدولِ لازم داده دارد');

// ===============================================================
T::group('⛔ رفت‌وبرگشتِ واقعی');

$dump = exportUserData($mineId);
$json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
T::ok(is_string($json) && strlen($json) > 200, 'خروجی ساخته شد');

$beforeCounts = importCounts($mineId);
T::ok(($beforeCounts['transactions'] ?? 0) === 2, 'پیش از بازگرداندن ۲ تراکنش هست',
    (string)($beforeCounts['transactions'] ?? 0));

// شناسه‌های قدیمی را نگه می‌داریم تا ثابت شود نگاشت واقعاً رخ داده
$oldTxIds = array_map('intval', array_column($dump['tables']['transactions'], 'id'));

// حالا دفتر را عمداً خراب می‌کنیم: یک تراکنشِ اضافه و حذفِ یک حساب‌
$ins("INSERT INTO transactions (user_id,type,amount,title,transaction_date,wallet_id)
      VALUES (:u,'income',77777,'ردیفِ اضافه که باید برود',:d,:w)",
    ['u' => $mineId, 'd' => $today, 'w' => $ids['w1']]);

$st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
$st->execute(['u' => $mineId]);
T::same(3, (int)$st->fetchColumn(), 'دفتر عمداً خراب شد (۳ تراکنش)');

$otherBefore = [];
foreach (importableTables() as $t) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id = :u");
    $s->execute(['u' => $otherId]);
    $otherBefore[$t] = (int)$s->fetchColumn();
}

$parsed = parseBackupFile($json);
T::ok(!empty($parsed['ok']), 'فایلِ خروجی دوباره خوانده شد');

$res = importUserData($mineId, $parsed['data']);
T::ok($res['ok'], 'بازگرداندن انجام شد', $res['message'] ?? '');

$after = importCounts($mineId);
T::same(2, $after['transactions'] ?? 0, '⛔ تراکنش‌ها دقیقاً به حالتِ فایل برگشتند');
T::same(2, $after['wallets'] ?? 0, 'حساب‌ها برگشتند');
T::same(1, $after['debts'] ?? 0, 'بدهی برگشت');
T::same(1, $after['debt_payments'] ?? 0, 'پرداختِ بدهی برگشت');
T::same(1, $after['trades'] ?? 0, 'معامله برگشت');
T::same(1, $after['trade_sales'] ?? 0, 'فروشِ معامله برگشت');
T::same(1, $after['savings_entries'] ?? 0, 'رکوردِ پس‌انداز برگشت');

// ---------- شناسه‌ها واقعاً نگاشت شدند ----------
$st = $pdo->prepare('SELECT id FROM transactions WHERE user_id = :u ORDER BY id');
$st->execute(['u' => $mineId]);
$newTxIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
T::ok(count(array_intersect($oldTxIds, $newTxIds)) === 0,
    '⛔ شناسه‌ها تازه‌اند، نه بازاستفاده‌ی شناسه‌ی فایل',
    'قدیم: ' . implode(',', $oldTxIds) . ' — تازه: ' . implode(',', $newTxIds));

// ---------- و پیوندها با همان نگاشت درست بسته شدند ----------
$st = $pdo->prepare(
    'SELECT t.title, w.name AS wallet, c.name AS cat
     FROM transactions t
     LEFT JOIN wallets w ON w.id = t.wallet_id
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :u ORDER BY t.amount'
);
$st->execute(['u' => $mineId]);
$rows = $st->fetchAll();

T::same('خریدِ شخصی', $rows[0]['title'] ?? null, 'تراکنشِ اول برگشت');
T::same('کیف پول', $rows[0]['wallet'] ?? null, '⛔ پیوندِ حساب درست بسته شد');
T::same('دسته‌ی شخصیِ تست', $rows[0]['cat'] ?? null, '⛔ پیوندِ دسته‌ی شخصی درست بسته شد');
T::same('بانک ملت', $rows[1]['wallet'] ?? null, 'حسابِ دوم هم درست بسته شد');

// ⛔ دسته‌ی پیش‌فرضِ برنامه در فایل نیست و باید با **نام** پیدا شده باشد
$defName = (string)$pdo->query("SELECT name FROM categories WHERE id = {$catOut}")->fetchColumn();
T::same($defName, $rows[1]['cat'] ?? null,
    '⛔ دسته‌ی پیش‌فرض با نام دوباره پیدا شد');

// پیوندِ فرزند به پدر
$st = $pdo->prepare(
    'SELECT COUNT(*) FROM debt_payments p
     JOIN debts d ON d.id = p.debt_id AND d.user_id = p.user_id
     WHERE p.user_id = :u'
);
$st->execute(['u' => $mineId]);
T::same(1, (int)$st->fetchColumn(), '⛔ پرداختِ بدهی به بدهیِ خودش وصل است');

$st = $pdo->prepare(
    'SELECT COUNT(*) FROM trade_sales s
     JOIN trades t ON t.id = s.trade_id AND t.user_id = s.user_id
     WHERE s.user_id = :u'
);
$st->execute(['u' => $mineId]);
T::same(1, (int)$st->fetchColumn(), '⛔ فروشِ معامله به معامله‌ی خودش وصل است');

// ---------- ردیفِ اضافه رفته است ----------
$st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND amount = 77777');
$st->execute(['u' => $mineId]);
T::same(0, (int)$st->fetchColumn(), 'ردیفِ اضافه با بازگرداندن رفت');

// ===============================================================
T::group('⛔ دفترِ کاربرِ دیگر دست‌نخورده مانده');

$otherLeak = [];
foreach ($otherBefore as $t => $n) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id = :u");
    $s->execute(['u' => $otherId]);
    $now = (int)$s->fetchColumn();
    if ($now !== $n) { $otherLeak[] = "$t: $n → $now"; }
}
T::bulk(count($otherBefore), $otherLeak,
    '⛔ هیچ ردیفی از کاربرِ دیگر کم یا زیاد نشد');

// ===============================================================
T::group('⛔ فایلِ دست‌کاری‌شده');

$before = $pdo->prepare('SELECT username, role, password_hash FROM users WHERE id = :u');
$before->execute(['u' => $mineId]);
$userBefore = $before->fetch();

$evil = $parsed['data'];
// ۱) پروفایلِ جعلی
$evil['profile'] = [
    'id'       => $mineId,
    'username' => 'hacked',
    'role'     => 'admin',
    'password_hash' => 'x',
    'pro_until'=> '9999-12-31',
];
// ۲) ردیفی که ادعا می‌کند مالِ کاربرِ دیگری است
$evil['tables']['transactions'][] = [
    'id' => 999999, 'user_id' => $otherId, 'type' => 'income',
    'amount' => 42, 'title' => 'ردیفِ جعلی', 'transaction_date' => $today,
];
// ۳) اعتبارنامه‌ی کاشته‌شده
$evil['tables']['api_tokens'] = [[
    'id' => 1, 'user_id' => $mineId, 'selector' => 'aaaaaaaa',
    'validator_hash' => str_repeat('b', 64), 'expires_at' => '2099-01-01 00:00:00',
]];
$evil['tables']['payments'] = [[
    'id' => 1, 'user_id' => $mineId, 'amount' => 0, 'status' => 'approved',
]];

$res2 = importUserData($mineId, $evil);
T::ok($res2['ok'], 'فایلِ دست‌کاری‌شده بدونِ خطا وارد شد', $res2['message'] ?? '');

$after2 = $pdo->prepare('SELECT username, role, password_hash FROM users WHERE id = :u');
$after2->execute(['u' => $mineId]);
$userAfter = $after2->fetch();

T::same($userBefore['username'], $userAfter['username'] ?? null,
    '⛔ نام کاربری از فایل نوشته نشد');
T::same($userBefore['role'], $userAfter['role'] ?? null,
    '⛔ نقشِ کاربر از فایل نوشته نشد — بالا بردنِ دسترسی ممکن نیست');
T::same($userBefore['password_hash'], $userAfter['password_hash'] ?? null,
    '⛔ هشِ رمز از فایل نوشته نشد');

$st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND amount = 42');
$st->execute(['u' => $otherId]);
T::same(0, (int)$st->fetchColumn(),
    '⛔ ردیفِ جعلی به کاربرِ دیگر نچسبید');

$st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u AND amount = 42');
$st->execute(['u' => $mineId]);
T::same(1, (int)$st->fetchColumn(),
    'همان ردیف به مالکِ واقعیِ نشست نسبت داده شد');

$st = $pdo->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = :u');
$st->execute(['u' => $mineId]);
T::same(0, (int)$st->fetchColumn(), '⛔ توکنِ کاشته‌شده وارد نشد');

$st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE user_id = :u');
$st->execute(['u' => $mineId]);
T::same(0, (int)$st->fetchColumn(), '⛔ پرداختِ جعلی وارد نشد');

T::ok(in_array('api_tokens', $res2['skipped'] ?? [], true),
    'جدولِ ردشده در نتیجه گزارش می‌شود');

// ===============================================================
T::group('⛔ جدولِ ناشناخته امتناع می‌کند و چیزی نمی‌برد');

$countBefore = importCounts($mineId);

$future = $parsed['data'];
$future['tables']['__table_from_the_future__'] = [['id' => 1, 'user_id' => $mineId]];

$res3 = importUserData($mineId, $future);
T::ok(!$res3['ok'], '⛔ فایلِ نسخه‌ی جدیدتر رد شد');
T::ok(str_contains($res3['message'] ?? '', '__table_from_the_future__'),
    'پیام می‌گوید کدام بخش ناشناخته است', $res3['message'] ?? '');

$countAfter = importCounts($mineId);
T::same($countBefore, $countAfter,
    '⛔ امتناع پیش از هر حذفی رخ داد — دفتر دست‌نخورده است');

// ===============================================================
T::group('ستونِ ناشناخته کنار گذاشته و گفته می‌شود');

$extra = $parsed['data'];
foreach ($extra['tables']['transactions'] as $i => $row) {
    $extra['tables']['transactions'][$i]['__column_from_the_future__'] = 'x';
}

$res4 = importUserData($mineId, $extra);
T::ok($res4['ok'], 'ستونِ ناشناخته فایل را رد نمی‌کند', $res4['message'] ?? '');
T::ok(in_array('transactions.__column_from_the_future__', $res4['dropped'] ?? [], true),
    '⛔ ستونِ کنارگذاشته‌شده در نتیجه **گفته** می‌شود',
    implode('، ', $res4['dropped'] ?? []));
T::same(2, importCounts($mineId)['transactions'] ?? 0,
    'و بقیه‌ی ردیف‌ها سرِ جایشان نشستند');

// ===============================================================
T::group('ستون‌های حساس دوباره رمز می‌شوند');

if (!Crypto::available()) {
    T::skip('رمزنگاری', 'APP_ENCRYPTION_KEY تنظیم نشده');
} else {
    $card = '6037991122334455';
    $pdo->prepare('UPDATE wallets SET card_number = :c WHERE user_id = :u LIMIT 1')
        ->execute(['c' => Crypto::encrypt($card), 'u' => $mineId]);

    $d2 = exportUserData($mineId);
    $cards = array_filter(array_column($d2['tables']['wallets'], 'card_number'));
    T::ok(in_array($card, $cards, true), 'خروجی شماره را **باز** می‌دهد');

    $res5 = importUserData($mineId, $d2);
    T::ok($res5['ok'], 'بازگرداندن با شماره کارت انجام شد', $res5['message'] ?? '');

    $st = $pdo->prepare('SELECT card_number FROM wallets WHERE user_id = :u AND card_number IS NOT NULL');
    $st->execute(['u' => $mineId]);
    $stored = (string)$st->fetchColumn();
    T::ok(Crypto::isEncrypted($stored),
        '⛔ شماره دوباره **رمزشده** نشست، نه خام');
    T::same($card, Crypto::decrypt($stored), 'و درست رمزگشایی می‌شود');

    // ⚠ دو بار رمز کردن یعنی رمزگشاییِ یک‌مرحله‌ای دیگر جواب نمی‌دهد
    $twice = Crypto::encryptRow(['card_number' => $stored], Crypto::WALLET_FIELDS);
    T::same($stored, $twice['card_number'], 'لایه‌ی دومِ رمز گذاشته نمی‌شود');
}

exit(T::report());
