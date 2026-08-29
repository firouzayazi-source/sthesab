<?php
/**
 * تست وصل شدن چک و طلب/بدهی به حساب‌ها.
 *
 * چهار چیز که اگر بشکنند، عدد غلط نشان می‌دهند بی‌آنکه خطایی بدهند:
 *  ۱. حساب پیش‌فرض («کیف پول») درست پیدا شود و هیچ پولی بی‌حساب نماند
 *  ۲. چک پاس‌شده روی موجودی همان حسابی بنشیند که کاربر انتخاب کرده
 *  ۳. تسویه‌ی طلب/بدهی پول را جابه‌جا کند و برداشتن تیک آن را پس بگیرد
 *  ۴. هیچ‌کدام از این‌ها ردیف درآمد/هزینه نسازد — وصول طلب درآمد نیست
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

// ---------- نگهبان: فقط خط فرمان ----------
// این فایل داخل ریشه‌ی وب است و بدون این نگهبان، هر کسی می‌توانست با
// باز کردن آدرسش در مرورگر تست را روی دیتابیس واقعی اجرا کند — تست‌ها
// کاربر و رکورد می‌سازند و پاک می‌کنند.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}


require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('اتصال پول به حساب');
    T::skip('تست اتصال پول به حساب', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('اتصال پول به حساب');
    T::skip('تست اتصال پول به حساب', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableHasColumn('cheques', 'settle_wallet_id') || !tableHasColumn('debt_payments', 'wallet_id')) {
    T::group('اتصال پول به حساب');
    T::skip('تست اتصال پول به حساب', 'migration_money_links اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
$TESTU = '__test_money_links_user';

$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    $oldId = $st->fetchColumn();
    if ($oldId) {
        // FK های RESTRICT: تراکنش، چک و طلب/بدهی باید پیش از کاربر بروند
        $pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $oldId]);
        $pdo->prepare('DELETE FROM cheques WHERE user_id = :u')->execute(['u' => $oldId]);
        $pdo->prepare('DELETE FROM debts WHERE user_id = :u')->execute(['u' => $oldId]);
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, 'کاربر تست پول', 'user')"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

// کیف پول (پیش‌فرض) و یک حساب بانکی
$pdo->prepare(
    "INSERT INTO wallets (user_id, name, kind, initial_balance, sort_order)
     VALUES (:u, 'کیف پول', 'cash', 0, 0)"
)->execute(['u' => $userId]);
$cashId = (int)$pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO wallets (user_id, name, kind, initial_balance, sort_order)
     VALUES (:u, 'ملی', 'bank', 1000000, 1)"
)->execute(['u' => $userId]);
$bankId = (int)$pdo->lastInsertId();

$balanceOf = function (int $walletId) use ($userId): int {
    foreach (walletBalances($userId) as $w) {
        if ((int)$w['id'] === $walletId) { return (int)$w['balance']; }
    }
    return PHP_INT_MIN;   // پیدا نشد — تست باید بشکند نه اینکه صفر بدهد
};

$txCount = function () use ($pdo, $userId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    return (int)$st->fetchColumn();
};

// ---------------------------------------------------------------
T::group('حساب پیش‌فرض');

T::same($cashId, defaultWalletId($userId), 'کیف پول حساب پیش‌فرض است');
T::same($bankId, resolveWalletId($userId, $bankId), 'حساب انتخاب‌شده همان می‌ماند');
T::same($cashId, resolveWalletId($userId, 0), 'بدون انتخاب، کیف پول جایش را می‌گیرد');
T::same($cashId, resolveWalletId($userId, 999999), 'حساب کاربر دیگر پذیرفته نمی‌شود');

// ---------------------------------------------------------------
T::group('چک پاس‌شده روی موجودی حساب');

$mkCheque = function (string $direction, int $amount, ?int $walletId, int $settled) use ($pdo, $userId): int {
    $pdo->prepare(
        'INSERT INTO cheques (user_id, direction, counterparty_name, amount, due_date, is_settled, settle_wallet_id)
         VALUES (:u, :d, :n, :a, :dd, :s, :w)'
    )->execute([
        'u' => $userId, 'd' => $direction, 'n' => 'طرف تست', 'a' => $amount,
        'dd' => today(), 's' => $settled, 'w' => $walletId,
    ]);
    return (int)$pdo->lastInsertId();
};

$bankBefore = $balanceOf($bankId);
$mkCheque('received', 100000, $bankId, 1);
T::same($bankBefore + 100000, $balanceOf($bankId), 'چک دریافتیِ پاس‌شده به حساب اضافه شد');

$mkCheque('issued', 40000, $bankId, 1);
T::same($bankBefore + 60000, $balanceOf($bankId), 'چک صادرهٔ پاس‌شده از حساب کم شد');

// چکِ پاس‌نشده نباید اثری داشته باشد
$mkCheque('received', 500000, $bankId, 0);
T::same($bankBefore + 60000, $balanceOf($bankId), 'چک در جریان روی موجودی اثر ندارد');

// چکِ پاس‌شده بدون حساب هم نباید اثری داشته باشد
$mkCheque('received', 700000, null, 1);
T::same($bankBefore + 60000, $balanceOf($bankId), 'چک بدون حساب روی هیچ حسابی نمی‌نشیند');

T::same(0, $txCount(), 'چک هیچ ردیف درآمد/هزینه نمی‌سازد');

// ---------------------------------------------------------------
T::group('پرداخت و تسویه‌ی طلب و بدهی');

$mkDebt = function (string $direction, int $amount) use ($pdo, $userId): int {
    $pdo->prepare(
        'INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount, entry_date, due_date, is_settled)
         VALUES (:u, :d, :n, :a, 0, :e, :dd, 0)'
    )->execute([
        'u' => $userId, 'd' => $direction, 'n' => 'طرف تست', 'a' => $amount,
        'e' => today(), 'dd' => today(),
    ]);
    return (int)$pdo->lastInsertId();
};

$pay = function (int $debtId, int $amount, ?int $walletId, int $isSettlement = 0) use ($pdo, $userId): void {
    $pdo->prepare(
        'INSERT INTO debt_payments (debt_id, user_id, wallet_id, amount, payment_date, is_settlement)
         VALUES (:d, :u, :w, :a, :dt, :s)'
    )->execute([
        'd' => $debtId, 'u' => $userId, 'w' => $walletId, 'a' => $amount,
        'dt' => today(), 's' => $isSettlement,
    ]);
};

$cashBefore = $balanceOf($cashId);

$recv = $mkDebt('receivable', 300000);
$pay($recv, 120000, $cashId);
T::same($cashBefore + 120000, $balanceOf($cashId), 'وصول جزئی طلب به حساب اضافه می‌شود');

$payable = $mkDebt('payable', 90000);
$pay($payable, 90000, $cashId, 1);
T::same($cashBefore + 30000, $balanceOf($cashId), 'پرداخت بدهی از حساب کم می‌شود');

// تسویه‌ی کامل طلب: باقیمانده به حساب دیگری می‌رود
$bankNow = $balanceOf($bankId);
$pay($recv, 180000, $bankId, 1);
T::same($bankNow + 180000, $balanceOf($bankId), 'باقیمانده‌ی تسویه به حساب انتخابی می‌رود');

// برداشتن تیک تسویه: فقط ردیف‌های is_settlement پس گرفته می‌شوند
$pdo->prepare('DELETE FROM debt_payments WHERE debt_id = :d AND user_id = :u AND is_settlement = 1')
    ->execute(['d' => $recv, 'u' => $userId]);
T::same($bankNow, $balanceOf($bankId), 'برداشتن تیک تسویه اثرش را پس می‌گیرد');
T::same($cashBefore + 120000 - 90000, $balanceOf($cashId), 'پرداخت دستی کاربر دست‌نخورده می‌ماند');

T::same(0, $txCount(), 'طلب و بدهی هیچ ردیف درآمد/هزینه نمی‌سازد');

// ---------------------------------------------------------------
T::group('تعدیل موجودی');

// تعدیل روی موجودی اولیه می‌نشیند، پس موجودی دقیقاً به همان اندازه
// جابه‌جا می‌شود و هیچ تراکنشی ساخته نمی‌شود.
$before = $balanceOf($cashId);
$pdo->prepare('UPDATE wallets SET initial_balance = initial_balance + 250000 WHERE id = :id AND user_id = :u')
    ->execute(['id' => $cashId, 'u' => $userId]);
T::same($before + 250000, $balanceOf($cashId), 'افزودن به موجودی اولیه، موجودی را بالا می‌برد');
T::same(0, $txCount(), 'تعدیل موجودی ردیف درآمد/هزینه نمی‌سازد');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
