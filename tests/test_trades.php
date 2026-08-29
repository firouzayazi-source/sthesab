<?php
/**
 * تست بخش معاملات.
 *
 * سه چیز که اگر بشکنند بی‌سروصدا می‌شکنند و اعداد غلط نشان می‌دهند:
 *  ۱. محاسبه‌ی سود — با فروش جزئی و هزینه‌ی جانبی
 *  ۲. اثر معامله روی موجودی حساب (walletBalances)
 *  ۳. جدایی کامل از درآمد/هزینه — معامله نباید تراکنش بسازد
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('معاملات');
    T::skip('تست معاملات', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('معاملات');
    T::skip('تست معاملات', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tradesTablesExist($pdo)) {
    T::group('معاملات');
    T::skip('تست معاملات', 'جدول trades نیست — migration را اجرا کنید');
    exit(T::report());
}

// ---------------------------------------------------------------
$TESTU = '__test_trades_user';
$cleanup = function () use ($pdo, $TESTU) {
    // trades و trade_sales و wallets با CASCADE پاک می‌شوند
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role, is_active)
     VALUES ("کاربر معاملات", :u, :h, "user", 1)'
)->execute(['u' => $TESTU, 'h' => password_hash('x12345678', PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO wallets (user_id, name, kind, initial_balance) VALUES (:u, "حساب تست", "bank", 200000000)'
)->execute(['u' => $uid]);
$wid = (int)$pdo->lastInsertId();

$balanceOf = function () use ($uid, $wid): int {
    foreach (walletBalances($uid) as $w) {
        if ((int)$w['id'] === $wid) { return (int)$w['balance']; }
    }
    return PHP_INT_MIN;
};

try {
    // ---------------------------------------------------------------
    T::group('پیش‌فرض و روشن/خاموش');

    T::same(false, tradesEnabled($pdo, $uid), 'بخش معاملات به‌صورت پیش‌فرض خاموش است');

    // ---------------------------------------------------------------
    T::group('ورودی تعداد');

    T::same(2.5,  sanitizeQty('۲٫۵'),  'تعداد با ارقام و ممیز فارسی خوانده می‌شود');
    T::same(1.0,  sanitizeQty('1'),    'عدد ساده');
    T::same(0.75, sanitizeQty('0.75'), 'اعشار لاتین');
    T::same(0.0,  sanitizeQty('abc'),  'ورودی بی‌معنی صفر می‌شود');

    // ---------------------------------------------------------------
    T::group('سود — معامله‌ی تک‌قلمی');

    // خرید ۱۰۰، فروش ۱۱۰ → سود ۱۰
    $pdo->prepare('INSERT INTO trades (user_id, title, qty, buy_total, buy_date, buy_wallet_id)
                   VALUES (:u, "گوشی", 1, 100000000, "2026-08-01", :w)')
        ->execute(['u' => $uid, 'w' => $wid]);
    $t1 = (int)$pdo->lastInsertId();

    $trades = tradesWithProgress($uid);
    T::same(1, count($trades), 'یک معامله ثبت شد');
    T::same(0, $trades[0]['realized_profit'], 'قبل از فروش، سود قطعی صفر است');
    T::same(false, $trades[0]['is_closed'], 'معامله باز است');
    T::same(100000000, $trades[0]['open_cost'], 'کل خرید هنوز سرمایه‌ی درگیر است');

    $pdo->prepare('INSERT INTO trade_sales (trade_id, user_id, qty, sale_total, sale_date, wallet_id)
                   VALUES (:t, :u, 1, 110000000, "2026-08-11", :w)')
        ->execute(['t' => $t1, 'u' => $uid, 'w' => $wid]);

    $trades = tradesWithProgress($uid);
    T::same(10000000, $trades[0]['realized_profit'], 'سود = فروش − خرید (۱۰ میلیون)');
    T::same(true, $trades[0]['is_closed'], 'بعد از فروش کامل، معامله بسته می‌شود');
    T::same(0, $trades[0]['open_cost'], 'سرمایه‌ی درگیر صفر شد');

    // ---------------------------------------------------------------
    T::group('سود — فروش جزئی و هزینه‌ی جانبی');

    // ۵ سکه، جمعاً ۵۰ + ۵ جانبی → بهای واحد ۱۱
    // فروش ۲ تا به ۳۰ → سود قطعی = 30 − 2×11 = 8
    $pdo->prepare('INSERT INTO trades (user_id, title, qty, buy_total, side_costs, buy_date)
                   VALUES (:u, "سکه", 5, 50000000, 5000000, "2026-08-05")')
        ->execute(['u' => $uid]);
    $t2 = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO trade_sales (trade_id, user_id, qty, sale_total, sale_date)
                   VALUES (:t, :u, 2, 30000000, "2026-08-15")')
        ->execute(['t' => $t2, 'u' => $uid]);

    $trades = tradesWithProgress($uid);
    $coin = null;
    foreach ($trades as $t) { if ((int)$t['id'] === $t2) { $coin = $t; } }
    T::same(8000000, $coin['realized_profit'], 'هزینه‌ی جانبی به نسبتِ فروخته‌شده در سود اثر می‌گذارد');
    T::same(3.0, (float)$coin['remaining_qty'], 'از ۵ سکه ۳ تا مانده');
    T::same(false, $coin['is_closed'], 'با فروش جزئی معامله باز می‌ماند');
    T::same(33000000, $coin['open_cost'], 'سرمایه‌ی مانده = ۳ × بهای واحد ۱۱');

    // ---------------------------------------------------------------
    T::group('جمع‌بندی صفحه');

    $sum = tradesSummary($trades);
    T::same(18000000, $sum['realized_profit'], 'جمع سود هر دو معامله');
    T::same(33000000, $sum['open_cost'], 'جمع سرمایه‌ی درگیر');
    T::same(1, $sum['open_count'], 'یک معامله باز');
    T::same(1, $sum['closed_count'], 'یک معامله بسته');

    // ---------------------------------------------------------------
    T::group('اثر روی موجودی حساب');

    // موجودی اولیه ۲۰۰ − خرید ۱۰۰ + فروش ۱۱۰ = ۲۱۰
    // (معامله‌ی سکه به حساب وصل نیست و نباید اثری بگذارد)
    T::same(210000000, $balanceOf(), 'خریدِ وصل‌شده کم و فروشِ وصل‌شده اضافه شد');

    // ---------------------------------------------------------------
    T::group('جدایی از درآمد/هزینه');

    $txCount = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :u');
    $txCount->execute(['u' => $uid]);
    T::same('0', (string)$txCount->fetchColumn(), 'هیچ تراکنشی از معامله‌ها ساخته نشده');

    // ---------------------------------------------------------------
    T::group('حذف زنجیره‌ای');

    $pdo->prepare('DELETE FROM trades WHERE id = :t AND user_id = :u')->execute(['t' => $t1, 'u' => $uid]);
    $orphan = $pdo->prepare('SELECT COUNT(*) FROM trade_sales WHERE trade_id = :t');
    $orphan->execute(['t' => $t1]);
    T::same('0', (string)$orphan->fetchColumn(), 'حذف معامله، فروش‌هایش را هم می‌برد');
    T::same(200000000, $balanceOf(), 'بعد از حذف، اثرش از موجودی هم رفت');

} finally {
    $cleanup();
}

exit(T::report());
