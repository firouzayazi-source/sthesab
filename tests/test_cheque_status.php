<?php
/**
 * تستِ چرخه‌ی کاملِ چک: در انتظار / پاس شد / برگشت خورد / خرج شد.
 *
 * چک تفاوت‌سازترین چیزِ این اپ برای بازار ایران است و مدلش تا پیش از
 * این فقط دو حالت داشت. چیزهایی که اگر بشکنند **عددِ پول را غلط
 * می‌کنند**، بی‌آنکه خطایی بدهند:
 *
 *  ۱. چکِ برگشتی و خرج‌شده نباید «در جریان» شمرده شوند. اگر بشوند،
 *     چکِ برگشتی **دو بار** حساب می‌شود: یک بار به‌عنوان چک، یک بار
 *     به‌عنوان طلبی که خودِ برنامه ساخته.
 *  ۲. `is_settled` باید مشتقِ درستِ `status` بماند — همه‌ی محاسبه‌ی
 *     موجودی به آن تکیه دارد.
 *  ۳. برگرداندنِ وضعیت باید طلبِ ساخته‌شده را پس بگیرد، مگر کاربر
 *     رویش پرداختی ثبت کرده باشد.
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('چرخه‌ی چک');
    T::skip('تست چرخه‌ی چک', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('چرخه‌ی چک');
    T::skip('تست چرخه‌ی چک', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableHasColumn('cheques', 'status')) {
    T::group('چرخه‌ی چک');
    T::skip('تست چرخه‌ی چک', 'migration_cheque_status اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
$TESTU = '__test_cheque_status_user';

$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['debt_payments', 'debts', 'cheques', 'transactions', 'wallets'] as $t) {
            try {
                $col = $t === 'debt_payments' ? 'user_id' : 'user_id';
                $pdo->prepare("DELETE FROM `$t` WHERE `$col` = :u")->execute(['u' => $id]);
            } catch (PDOException $e) { /* جدول شاید نباشد */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, 'کاربر تست چک', 'user')"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
$walletId = ensureDefaultWallet($userId);

$mkCheque = function (string $dir, int $amount, string $who) use ($pdo, $userId): int {
    $pdo->prepare(
        "INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date, is_settled, status)
         VALUES (:u, :d, :w, :a, :due, 0, 'pending')"
    )->execute(['u' => $userId, 'd' => $dir, 'w' => $who, 'a' => $amount,
                'due' => date('Y-m-d', strtotime('+10 days'))]);
    return (int)$pdo->lastInsertId();
};

$activeCount = function () use ($pdo, $userId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM cheques WHERE user_id = :u AND ' . chequeActiveSql());
    $st->execute(['u' => $userId]);
    return (int)$st->fetchColumn();
};

$setStatus = function (int $id, string $status, ?int $wallet = null) use ($pdo, $userId) {
    $pdo->prepare(
        "UPDATE cheques SET status = :s, is_settled = :i, settle_wallet_id = :w
         WHERE id = :id AND user_id = :u"
    )->execute([
        's' => $status,
        'i' => $status === 'cleared' ? 1 : 0,
        'w' => $status === 'cleared' ? $wallet : null,
        'id' => $id, 'u' => $userId,
    ]);
};

// ---------------------------------------------------------------
T::group('«در جریان» یعنی pending، نه فقط «پاس نشده»');

$c1 = $mkCheque('received', 5000000, 'شرکت الف');
$c2 = $mkCheque('received', 3000000, 'شرکت ب');
$c3 = $mkCheque('issued',   2000000, 'شرکت ج');

T::same(3, $activeCount(), 'هر سه چکِ تازه در جریان‌اند');

// ⛔ هسته‌ی ماجرا: چکِ برگشتی و خرج‌شده هم `is_settled = 0` دارند.
//    اگر chequeActiveSql() فقط همان را بسنجد، این‌ها همچنان «در
//    جریان» شمرده می‌شوند — و چکِ برگشتی که طلب هم ساخته، دو بار.
$setStatus($c2, 'bounced');
T::same(2, $activeCount(), 'چکِ برگشتی از جریان خارج می‌شود');

$setStatus($c3, 'endorsed');
T::same(1, $activeCount(), 'چکِ خرج‌شده هم از جریان خارج می‌شود');

$setStatus($c1, 'cleared', $walletId);
T::same(0, $activeCount(), 'چکِ پاس‌شده هم در جریان نیست');

// ---------------------------------------------------------------
T::group('is_settled مشتقِ درستِ status می‌ماند');

// ⛔ همه‌ی محاسبه‌ی موجودی (`walletBalances()`) به `is_settled` تکیه
//    دارد. اگر از `status` جدا بیفتد، پولِ چکِ پاس‌شده یا در موجودی
//    نمی‌آید یا چکِ برگشتی به اشتباه می‌آید.
$rows = $pdo->prepare('SELECT id, status, is_settled FROM cheques WHERE user_id = :u');
$rows->execute(['u' => $userId]);
$bad = [];
foreach ($rows->fetchAll() as $r) {
    $expected = $r['status'] === 'cleared' ? 1 : 0;
    if ((int)$r['is_settled'] !== $expected) {
        $bad[] = "چک {$r['id']}: status={$r['status']} ولی is_settled={$r['is_settled']}";
    }
}
T::bulk(3, $bad, 'is_settled = 1 ⇔ status = cleared');

// ---------------------------------------------------------------
T::group('چکِ برگشتی در آینده مالی دوبار نمی‌آید');

// financialEvents هم باید همان تعریف را داشته باشد، وگرنه صفحه‌ی
// «آینده مالی» چکی را نشان می‌دهد که کارش تمام شده.
$setStatus($c2, 'bounced');
$events = financialEvents($userId, date('Y-m-d', strtotime('-90 days')),
                                   date('Y-m-d', strtotime('+60 days')));
$hits = 0;
foreach ($events as $e) {
    if ($e['kind'] === 'cheque' && (int)$e['amount'] === 3000000) { $hits++; }
}
T::same(0, $hits, 'چکِ برگشتی اصلاً در رویدادها نمی‌آید');

// و طلبی که برایش ساخته شده باید بیاید — یک بار، نه دو بار
$pdo->prepare(
    "INSERT INTO debts (user_id, cheque_id, direction, counterparty_name, amount,
                        paid_amount, entry_date, due_date, note, is_settled)
     VALUES (:u, :c, 'receivable', 'شرکت ب', 3000000, 0, :e, :d, 'از چکِ برگشت‌خورده', 0)"
)->execute(['u' => $userId, 'c' => $c2, 'e' => today(),
            'd' => date('Y-m-d', strtotime('+10 days'))]);

$events = financialEvents($userId, date('Y-m-d', strtotime('-90 days')),
                                   date('Y-m-d', strtotime('+60 days')));
$hits = 0;
foreach ($events as $e) {
    if ((int)$e['amount'] === 3000000) { $hits++; }
}
T::same(1, $hits, 'مبلغ فقط یک بار می‌آید — نه به‌عنوان چک و نه دوباره');

// ---------------------------------------------------------------
T::group('پیوندِ چک و طلب');

$st = $pdo->prepare('SELECT COUNT(*) FROM debts WHERE cheque_id = :c AND user_id = :u');
$st->execute(['c' => $c2, 'u' => $userId]);
T::same(1, (int)$st->fetchColumn(), 'طلب به چک پیوند خورده است');

// ⛔ بدون این پیوند، برگرداندنِ وضعیتِ چک طلبِ یتیم به جا می‌گذاشت و
//    مبلغ برای همیشه دو بار در دفتر می‌ماند.
T::ok(tableHasColumn('debts', 'cheque_id'), 'ستون پیوند وجود دارد');

// ---------------------------------------------------------------
T::group('برچسبِ وضعیت یک جا تعریف می‌شود');

foreach (['pending', 'cleared', 'bounced', 'endorsed'] as $s) {
    $m = chequeStatusMeta($s);
    // ⚠ `«{$s}»` نه `«$s»` — PHP بایتِ » را جزء نامِ متغیر می‌خواند و
    //   پیام وسطش خالی درمی‌آید. همان دامی که CLAUDE.md ثبتش کرده.
    T::ok($m['label'] !== '' && $m['class'] !== '', "برچسب و کلاسِ «{$s}» تعریف شده");
}
// وضعیتِ ناشناخته نباید صفحه را بشکند
$m = chequeStatusMeta('چیزِ عجیب');
T::same('در انتظار', $m['label'], 'وضعیتِ ناشناخته به «در انتظار» می‌افتد');

// ---------------------------------------------------------------
T::group('chequeActiveSql با alias هم درست است');

// در `cheques.php` جدول alias دارد (`ch`)؛ اگر تابع alias را نادیده
// بگیرد، کوئری با خطای «ستون مبهم» می‌شکند یا بدتر، جدولِ اشتباه را
// می‌سنجد.
T::ok(str_contains(chequeActiveSql('ch'), 'ch.is_settled'), 'alias روی is_settled می‌نشیند');
T::ok(str_contains(chequeActiveSql('ch'), 'ch.status'), 'alias روی status می‌نشیند');
$q = $pdo->prepare('SELECT COUNT(*) FROM cheques ch LEFT JOIN banks b ON b.id = ch.bank_id
                    WHERE ch.user_id = :u AND ' . chequeActiveSql('ch'));
$q->execute(['u' => $userId]);
T::ok($q->fetchColumn() !== false, 'کوئریِ alias‌دار واقعاً اجرا می‌شود');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
