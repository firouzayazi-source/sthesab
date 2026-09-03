<?php
/**
 * تستِ اعدادی که «تفسیر» می‌کنند، نه فقط جمع می‌زنند.
 *
 * این‌ها بدترین نوعِ باگ‌اند: هیچ خطایی نمی‌دهند، صفحه سالم بالا
 * می‌آید، و فقط عدد اشتباه است — پس کسی متوجه نمی‌شود و بدتر، کاربر
 * بر اساسش تصمیم مالی می‌گیرد.
 *
 *  ۱. monthComparison() باید **هم‌روز** مقایسه کند، نه ماهِ ناتمام در
 *     برابر ماهِ کامل.
 *  ۲. safeToSpend() باید تعهدهای **سررسیدگذشته** را هم بشمارد — وگرنه
 *     دقیقاً وقتی خوش‌بین است که کاربر در دردسر است.
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
    T::group('اعداد تفسیری');
    T::skip('تست اعداد تفسیری', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('اعداد تفسیری');
    T::skip('تست اعداد تفسیری', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
$TESTU = '__test_insights_user';

$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['transactions', 'debts', 'cheques', 'wallets'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدول شاید نباشد */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, 'کاربر تست تفسیر', 'user')"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
$walletId = ensureDefaultWallet($userId);

$addTx = function (string $date, string $type, int $amount) use ($pdo, $userId, $walletId) {
    $pdo->prepare(
        'INSERT INTO transactions (user_id, wallet_id, type, amount, title, transaction_date)
         VALUES (:u, :w, :t, :a, :ti, :d)'
    )->execute(['u' => $userId, 'w' => $walletId, 't' => $type,
                'a' => $amount, 'ti' => 'تست', 'd' => $date]);
};

// روزِ شمسیِ امروز — کلِ منطق حولِ همین می‌چرخد
[$jy, $jm, $jd] = gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
$jalaliToG = function (int $y, int $m, int $d): string {
    $g = jalaliToGregorian($y, $m, $d);
    return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
};

$pJy = $jy; $pJm = $jm - 1;
if ($pJm < 1) { $pJm = 12; $pJy--; }
$prevLen = jalaliMonthLength($pJy, $pJm);

// ---------------------------------------------------------------
T::group('مقایسه‌ی ماه باید هم‌روز باشد، نه ناتمام در برابر کامل');

// ⛔ بازتولیدِ دقیقِ باگ:
//    ماهِ قبل هر روز ۱٬۰۰۰٬۰۰۰ خرج داشته. ماهِ جاری هم تا امروز هر
//    روز ۱٬۰۰۰٬۰۰۰ — یعنی آهنگِ خرج **دقیقاً یکی** است و درست‌ترین
//    جواب «۰٪» است.
//
//    نسخه‌ی قبلی ماهِ جاری را تا امروز و ماهِ قبل را کامل می‌خواند، پس
//    اگر امروز روزِ ۱۱ ام باشد ۱۱ میلیون با ۳۰ میلیون مقایسه می‌شد و
//    اپ می‌گفت «هزینه ۶۳٪ کم شده». هر چه اولِ ماه‌تر، دروغ بزرگ‌تر.
for ($d = 1; $d <= $jd; $d++) {
    $addTx($jalaliToG($jy, $jm, $d), 'expense', 1000000);
}
for ($d = 1; $d <= $prevLen; $d++) {
    $addTx($jalaliToG($pJy, $pJm, $d), 'expense', 1000000);
}

$cmp = monthComparison($userId);

T::same($jd * 1000000, $cmp['current_expense'], 'هزینه‌ی ماهِ جاری تا امروز');
T::same(min($jd, $prevLen) * 1000000, $cmp['prev_expense'],
    'هزینه‌ی ماهِ قبل فقط تا همان روز شمرده می‌شود، نه کلِ ماه');
T::same(0, $cmp['expense_change'],
    'آهنگِ یکسانِ خرج یعنی ۰٪ — نه یک کاهشِ ساختگی');

// اگر ماهِ قبل کوتاه‌تر از روزِ امروز باشد (اسفندِ ۲۹ روزه و امروز ۳۰)،
// بازه نباید به ماهِ بعد سرریز کند.
T::ok($cmp['prev_day'] <= $prevLen,
    'روزِ پایانیِ ماهِ قبل از طولِ همان ماه بیشتر نمی‌شود',
    "prev_day={$cmp['prev_day']} prevLen={$prevLen}");
T::same($jd, $cmp['elapsed_days'], 'روزِ سپری‌شده گزارش می‌شود');
T::same(jalaliMonthLength($jy, $jm), $cmp['total_days'], 'طولِ ماهِ جاری گزارش می‌شود');

// و وقتی واقعاً بیشتر خرج شده، باید بیشتر نشان دهد
$addTx($jalaliToG($jy, $jm, $jd), 'expense', $jd * 1000000);
$cmp2 = monthComparison($userId);
T::ok($cmp2['expense_change'] > 0, 'خرجِ واقعاً بیشتر، افزایش نشان می‌دهد',
    'expense_change=' . $cmp2['expense_change']);

// ---------------------------------------------------------------
T::group('پنجره‌ی مقایسه — شاخه‌هایی که «امروز» به آن‌ها نمی‌رسد');

// ⚠ چرا این گروه جداست: شاخه‌ی «ماهِ قبل کوتاه‌تر از امروز است» فقط
//    چند روز در سال رخ می‌دهد (فروردینِ ۳۰ ام وقتی اسفند ۲۹ روز بوده).
//    تستِ بالا فقط با تاریخِ امروز کار می‌کند، پس آن شاخه را نمی‌بیند:
//    یک بار برداشتنِ min() از کد امتحان شد و تست همچنان سبز ماند.
//    monthComparisonWindow() عمداً تابعِ خالص است تا هر تاریخی را
//    بشود مستقیم به آن داد.

// ۱. حالتِ عادی: هر دو ماه به‌اندازه‌ی کافی بلند
$w = monthComparisonWindow(1404, 6, 11);          // شهریور ۱۱ → مرداد ۱۱
T::same(5,  $w['prev_month'], 'شهریور → مرداد');
T::same(11, $w['prev_day'],   'روزِ پایانیِ ماهِ قبل همان روزِ امروز است');

// ۲. ⛔ ماهِ قبل کوتاه‌تر است — همان شاخه‌ای که min() برایش هست.
//    سالِ عادی و کبیسه از خودِ کد گرفته می‌شوند نه از حافظه: بار اول
//    «۱۴۰۳ عادی است» فرض شد و غلط بود (کبیسه است)، پس تست به‌دلیلِ
//    اشتباهِ خودش قرمز شد نه به‌دلیلِ باگِ کد.
$plainYear = null; $leapYear = null;
for ($y = 1400; $y <= 1425; $y++) {
    $len = jalaliMonthLength($y, 12);
    if ($len === 29 && $plainYear === null) { $plainYear = $y; }
    if ($len === 30 && $leapYear  === null) { $leapYear  = $y; }
}
T::ok($plainYear !== null && $leapYear !== null,
    'یک سالِ عادی و یک سالِ کبیسه پیدا شد',
    "عادی=$plainYear کبیسه=$leapYear");

// فروردینِ ۳۰ ام، وقتی اسفندِ قبلش ۲۹ روز بوده
$w = monthComparisonWindow($plainYear + 1, 1, 30);
T::same(12, $w['prev_month'], 'فروردین → اسفندِ سالِ قبل');
T::same($plainYear, $w['prev_year'], 'سال هم یکی عقب می‌رود');
T::same(29, $w['prev_day'], "روزِ پایانی به ۲۹ بریده می‌شود (اسفند $plainYear عادی است)");
T::ok($w['prev_end'] < $w['cur_start'],
    'بازه‌ی ماهِ قبل به فروردین سرریز نمی‌کند',
    "prev_end={$w['prev_end']} cur_start={$w['cur_start']}");

// ۳. اسفندِ کبیسه — نباید بی‌جهت بریده شود
$w = monthComparisonWindow($leapYear + 1, 1, 30);
T::same(30, $w['prev_day'], "اسفندِ کبیسه ($leapYear) بریده نمی‌شود");

// ۴. هیچ تاریخی نباید پنجره‌ی نامعتبر بسازد — جاروی کاملِ چند سال
$bad = [];
for ($y = 1402; $y <= 1406; $y++) {
    for ($m = 1; $m <= 12; $m++) {
        for ($d = 1; $d <= jalaliMonthLength($y, $m); $d++) {
            $w = monthComparisonWindow($y, $m, $d);
            if ($w['prev_start'] > $w['prev_end'])   { $bad[] = "$y/$m/$d شروع بعد از پایان"; }
            if ($w['prev_end'] >= $w['cur_start'])   { $bad[] = "$y/$m/$d سرریز به ماهِ جاری"; }
            if ($w['prev_day'] > $w['prev_len'])     { $bad[] = "$y/$m/$d روز > طولِ ماه"; }
            if ($w['prev_day'] > $d)                 { $bad[] = "$y/$m/$d روزِ قبل > امروز"; }
        }
    }
}
T::bulk(5 * 365, $bad, 'پنجره‌ی مقایسه برای هر روزِ پنج سال معتبر است');

// ---------------------------------------------------------------
T::group('«پول قابل خرج» باید سررسیدگذشته‌ها را هم بشمارد');

// ⛔ بازتولیدِ باگ: safeToSpend() پنجره را از today() شروع می‌کرد، پس
//    بدهیِ عقب‌افتاده اصلاً دیده نمی‌شد. یعنی عدد دقیقاً وقتی خوش‌بین
//    بود که کاربر در دردسر است — در حالی که بدهیِ دیرکرده قطعی‌ترین
//    تعهدی است که آدم دارد.
$pdo->prepare('DELETE FROM transactions WHERE user_id = :u')->execute(['u' => $userId]);

// موجودی: ۱۰ میلیون
$pdo->prepare('UPDATE wallets SET initial_balance = 10000000 WHERE id = :w')
    ->execute(['w' => $walletId]);

$mkDebt = function (string $due, int $amount) use ($pdo, $userId) {
    $pdo->prepare(
        "INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount,
                            entry_date, due_date, is_settled)
         VALUES (:u, 'payable', 'طرف تست', :a, 0, :e, :d, 0)"
    )->execute(['u' => $userId, 'a' => $amount, 'e' => date('Y-m-d', strtotime('-120 days')), 'd' => $due]);
};

$pastDue   = date('Y-m-d', strtotime('-10 days'));
$futureDue = date('Y-m-d', strtotime('+10 days'));
$mkDebt($pastDue, 4000000);     // سررسید گذشته
$mkDebt($futureDue, 1000000);   // پیشِ رو

$sts = safeToSpend($userId, 30);

T::same(10000000, $sts['balance'], 'موجودی کل درست خوانده می‌شود');
T::same(5000000, $sts['commitments'],
    'هر دو تعهد شمرده می‌شوند — گذشته و آینده');
T::same(4000000, $sts['overdue'],
    'سهمِ سررسیدگذشته جدا گزارش می‌شود');
T::same(5000000, $sts['available'],
    'پول قابل خرج = موجودی منهای هر دو تعهد');

// اگر هیچ چیزی سررسیدگذشته نباشد، overdue باید صفر باشد — نه اینکه
// هر تعهدی را «گذشته» بشمارد.
$pdo->prepare('DELETE FROM debts WHERE user_id = :u AND due_date = :d')
    ->execute(['u' => $userId, 'd' => $pastDue]);
$sts2 = safeToSpend($userId, 30);
T::same(0, $sts2['overdue'], 'بدون سررسیدگذشته، سهمش صفر است');
T::same(1000000, $sts2['commitments'], 'تعهدِ آینده همچنان شمرده می‌شود');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
