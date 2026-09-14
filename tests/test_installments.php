<?php
/**
 * تستِ وام و قسط.
 *
 * رایج‌ترین بدهیِ خانوارِ ایرانی وامِ قسطی است و مدلِ قبلی فقط یک مبلغ
 * با **یک** سررسید داشت. چیزهایی که اگر بشکنند بی‌صدا عددِ پول را
 * غلط می‌کنند:
 *
 *  ۱. جمعِ اقساط باید **دقیقاً** برابر مبلغِ وام باشد. اگر باقیمانده‌ی
 *     تقسیم گم شود، کاربر یک بدهیِ چندریالیِ ابدی پیدا می‌کند.
 *  ۲. بدهیِ قسطی نباید **هم** به‌صورت اقساط بیاید **هم** به‌صورت یک
 *     مبلغِ درشت — یعنی دوبار شمردنِ همان پول در «آینده مالی» و
 *     «پول قابل خرج».
 *  ۳. «کدام قسط پرداخت شده» از جمعِ پرداختی می‌آید نه از تاریخ؛ کاربر
 *     ممکن است دو قسط را یک‌جا بدهد.
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
    T::group('وام و قسط');
    T::blocked('تست قسط', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('وام و قسط');
    T::blocked('تست قسط', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
T::group('برنامه‌ی قسط — تابعِ خالص، بدون دیتابیس');

// ⛔ جمعِ اقساط باید دقیقاً برابرِ وام باشد. ۱۰ میلیون بر ۳ تقسیم
//    نمی‌شود؛ باقیمانده باید به قسطِ آخر برود، وگرنه ۱ ریال بدهیِ
//    ابدی می‌ماند که هرگز تسویه نمی‌شود.
$mk = fn(int $amount, int $count, int $paid = 0, string $every = 'monthly') => [
    'amount' => $amount, 'paid_amount' => $paid,
    'installment_count' => $count, 'installment_every' => $every,
    'first_installment_date' => '2026-09-10', 'due_date' => '2026-12-10',
];

$bad = [];
foreach ([[10000000, 3], [12000000, 12], [999999999, 7], [5000000, 480]] as [$amt, $n]) {
    $sched = debtInstallments($mk($amt, $n));
    $sum = array_sum(array_column($sched, 'amount'));
    if (count($sched) !== $n) { $bad[] = "$amt/$n: تعدادِ اقساط " . count($sched); }
    if ($sum !== $amt)        { $bad[] = "$amt/$n: جمع $sum ≠ $amt"; }
    foreach ($sched as $i) {
        if ($i['amount'] <= 0) { $bad[] = "$amt/$n: قسطِ صفر یا منفی"; break; }
    }
}
T::bulk(4, $bad, 'جمعِ اقساط همیشه دقیقاً برابرِ مبلغِ وام است');

// ⚠ حالتِ لبه‌ای که خودِ تست پیدا کرد: مبلغِ کمتر از تعدادِ اقساط،
//   قسط‌های **صفر** می‌ساخت — هم در کارت «۰ تومان» نشان می‌داد هم
//   به‌عنوان تعهدِ صفر وارد آینده‌ی مالی می‌شد. حالا مثل بدهیِ ساده
//   رفتار می‌کند.
T::same([], debtInstallments($mk(1, 2)), 'مبلغِ کمتر از تعدادِ اقساط، برنامه‌ی قسط نمی‌سازد');
T::same([], debtInstallments($mk(5, 10)), 'همین‌طور ۵ تومان در ۱۰ قسط');
T::same(2, count(debtInstallments($mk(2, 2))), 'ولی ۲ تومان در ۲ قسط درست است');

// باقیمانده روی قسطِ آخر، نه پخش‌شده
$sched = debtInstallments($mk(10000000, 3));
T::same(3333333, $sched[0]['amount'], 'قسط اول');
T::same(3333333, $sched[1]['amount'], 'قسط دوم');
T::same(3333334, $sched[2]['amount'], 'باقیمانده روی قسطِ آخر می‌نشیند');

// تاریخ‌ها با تقویمِ شمسی جلو می‌روند
$dates = array_column($sched, 'date');
T::ok($dates[0] < $dates[1] && $dates[1] < $dates[2], 'تاریخ‌ها صعودی‌اند');
T::same('2026-09-10', $dates[0], 'اولین قسط روی تاریخِ داده‌شده است');

// هفتگی
$w = debtInstallments($mk(300, 3, 0, 'weekly'));
T::same(7, (int)((strtotime($w[1]['date']) - strtotime($w[0]['date'])) / 86400),
    'دوره‌ی هفتگی هفت روز است');

// کمتر از دو قسط یعنی «قسطی نیست»
T::same([], debtInstallments($mk(1000, 1)), 'یک قسط یعنی قسطی نیست');
T::same([], debtInstallments($mk(1000, 0)), 'صفر قسط یعنی قسطی نیست');

// ---------------------------------------------------------------
T::group('کدام قسط پرداخت شده — از جمعِ پرداختی، نه از تاریخ');

// ⚠ کاربر ممکن است دو قسط را یک‌جا بدهد یا زودتر بپردازد. پول پول
//   است و ترتیبش اهمیتی ندارد.
$s = debtInstallments($mk(3000000, 3, 0));
T::same(0, count(array_filter($s, fn($i) => $i['paid'])), 'بدون پرداخت، هیچ قسطی پرداخت‌شده نیست');

$s = debtInstallments($mk(3000000, 3, 1000000));
T::same(1, count(array_filter($s, fn($i) => $i['paid'])), 'یک قسط پرداخت‌شده');
T::same(2, nextDebtInstallment($mk(3000000, 3, 1000000))['seq'], 'قسط بعدی دومی است');

$s = debtInstallments($mk(3000000, 3, 2000000));
T::same(2, count(array_filter($s, fn($i) => $i['paid'])), 'دو قسطِ یک‌جا پرداخت‌شده');

$s = debtInstallments($mk(3000000, 3, 3000000));
T::same(3, count(array_filter($s, fn($i) => $i['paid'])), 'همه پرداخت‌شده');
T::same(null, nextDebtInstallment($mk(3000000, 3, 3000000)), 'قسط بعدی وجود ندارد');

// پرداختِ ناقص نباید قسط را «پرداخت‌شده» نشان دهد
$s = debtInstallments($mk(3000000, 3, 999999));
T::same(0, count(array_filter($s, fn($i) => $i['paid'])), 'پرداختِ ناقص قسط را تمام نمی‌کند');

// ---------------------------------------------------------------
if (!tableHasColumn('debts', 'installment_count')) {
    T::group('رویدادهای قسط');
    T::skip('رویدادهای قسط', 'migration_installments اجرا نشده');
    exit(T::report());
}

T::group('اقساط در آینده مالی — یک بار، نه دو بار');

$TESTU = '__test_installment_user';
$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['debt_payments', 'debts', 'transactions', 'wallets'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدول شاید نباشد */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, 'کاربر تست قسط', 'user')"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
ensureDefaultWallet($userId);

// ⛔ سررسیدِ آخر عمداً **داخلِ** بازه است — همان حالتی که دوبار شمردن
//    در آن رخ می‌دهد: بدهی هم از کوئریِ ساده می‌آید هم از حلقه‌ی قسط.
$pdo->prepare(
    "INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount,
                        entry_date, due_date, installment_count, installment_every,
                        first_installment_date, is_settled)
     VALUES (:u, 'payable', 'بانک تست', 3000000, 0, :e, :d, 3, 'monthly', :f, 0)"
)->execute([
    'u' => $userId, 'e' => today(),
    'd' => date('Y-m-d', strtotime('+2 months')),
    'f' => today(),
]);

$events = financialEvents($userId, date('Y-m-d', strtotime('-90 days')),
                                   date('Y-m-d', strtotime('+120 days')));
$mine = array_values(array_filter($events, fn($e) => str_contains($e['title'], 'بانک تست')));

T::same(3, count($mine), 'دقیقاً سه رویداد — نه چهارتا (سه قسط + یک مبلغِ درشت)');
T::same(3000000, array_sum(array_column($mine, 'amount')),
    'جمعِ رویدادها برابرِ وام است — همان پول دو بار شمرده نشده');
T::ok(str_contains($mine[0]['title'], 'قسط'), 'عنوانِ رویداد شماره‌ی قسط را می‌گوید');

// بدهیِ **ساده** باید مثل قبل یک رویداد بسازد — این تغییر نباید
// رفتارِ بدهی‌های موجود را عوض کند.
$pdo->prepare(
    "INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount,
                        entry_date, due_date, is_settled)
     VALUES (:u, 'payable', 'قرضِ ساده', 500000, 0, :e, :d, 0)"
)->execute(['u' => $userId, 'e' => today(), 'd' => date('Y-m-d', strtotime('+5 days'))]);

$events = financialEvents($userId, date('Y-m-d', strtotime('-90 days')),
                                   date('Y-m-d', strtotime('+120 days')));
$simple = array_values(array_filter($events, fn($e) => str_contains($e['title'], 'قرضِ ساده')));
T::same(1, count($simple), 'بدهیِ ساده همچنان یک رویداد است');
T::same(500000, (int)$simple[0]['amount'], 'مبلغش کاملِ باقیمانده است');
T::ok(!str_contains($simple[0]['title'], 'قسط'), 'عنوانش حرفی از قسط نمی‌زند');

// ---------------------------------------------------------------
T::group('«پول قابل خرج» قسطِ ماهِ بعد را می‌بیند');

// ⛔ دلیلِ اصلیِ کلِ این کار: بدونش، وامِ ۳۶ ماهه فقط در سررسیدِ آخر
//    دیده می‌شد و عددِ «قابل خرج» تا سه سال به‌شدت خوش‌بین بود.
$sts = safeToSpend($userId, 30);
T::ok($sts['commitments'] >= 1500000,
    'تعهدهای ۳۰ روزه شاملِ قسطِ این ماه و قرضِ ساده است',
    'commitments=' . $sts['commitments']);

$cleanup();
exit(T::report());
