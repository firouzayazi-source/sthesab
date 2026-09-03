<?php
/**
 * تستِ طرحِ اشتراک و چرخه‌ی پرداخت.
 *
 * ⛔ سه چیزی که اگر بشکنند بی‌صدا پول یا اعتماد می‌برند:
 *
 *  ۱. **تمدید نباید روزهای باقی‌مانده را بخورد.** اگر تمدید همیشه از
 *     «امروز» حساب شود، کسی که یک ماه زودتر تمدید می‌کند آن یک ماه را
 *     از دست می‌دهد — یعنی تنبیهِ کاربرِ خوش‌حساب، و او فقط می‌بیند
 *     عددِ روزها کمتر از انتظارش است.
 *
 *  ۲. **انقضا باید خودش اتفاق بیفتد.** با یک کلیدِ روشن/خاموش، خاموش
 *     کردنش کارِ یک زمان‌بند می‌شد و آن زمان‌بند روزی از کار می‌افتاد و
 *     همه بی‌سروصدا Pro می‌ماندند. اینجا تاریخ است.
 *
 *  ۳. **اجرای محدودیت پیش‌فرض خاموش است.** یک به‌روزرسانی نباید چیزی
 *     را از کاربرِ فعلی بگیرد.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('طرح اشتراک');
    T::skip('تست اشتراک', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plan.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('طرح اشتراک');
    T::skip('تست اشتراک', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

T::group('طرح اشتراک');

if (!plansAvailable()) {
    T::skip('تست اشتراک', 'migration_plans اجرا نشده');
    exit(T::report());
}

// تنظیمات را دست‌نخورده برگردان
$origEnforce = getSetting(PLAN_ENFORCE_SETTING, '0');
$origPrice   = getSetting(PLAN_PRICE_SETTING, '0');
register_shutdown_function(function () use ($origEnforce, $origPrice) {
    setSetting(PLAN_ENFORCE_SETTING, $origEnforce);
    setSetting(PLAN_PRICE_SETTING, $origPrice);
});

// ---------------------------------------------------------------
T::group('پیش‌فرض: هیچ چیزی بسته نیست');

$pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k')
    ->execute(['k' => PLAN_ENFORCE_SETTING]);
T::same(false, planEnforced(), '⛔ بدونِ تنظیم، محدودیت اجرا نمی‌شود');

// ---------------------------------------------------------------
$TESTU = '__test_plan_user';
$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['payments', 'wallets'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* بی‌خیال */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();
register_shutdown_function($cleanup);

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست اشتراک', 'user', 1)"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();

$plan = userPlan($uid);
T::same('free', $plan['plan'], 'کاربرِ تازه در طرح رایگان است');
T::same(false, $plan['is_pro'], 'و Pro نیست');
T::same(null, $plan['pro_until'], 'تاریخِ انقضا ندارد');

// با اجرای خاموش، همه چیز باز است
T::same(true, planAllows($uid, 'trades'), 'با اجرای خاموش، بخش معاملات باز است');
T::same(true, planAllows($uid, 'api'), 'و API هم');

setSetting(PLAN_ENFORCE_SETTING, '1');
T::same(false, planAllows($uid, 'trades'), 'با اجرای روشن، معاملات برای رایگان بسته است');
T::same(true,  planAllows($uid, 'transactions'),
    '⛔ ولی هسته‌ی اپ (تراکنش) هرگز بسته نمی‌شود');
T::same(true,  planAllows($uid, 'cheques'), 'چک هم همین‌طور');
setSetting(PLAN_ENFORCE_SETTING, '0');

// ---------------------------------------------------------------
T::group('اعلام پرداخت');

setSetting(PLAN_PRICE_SETTING, '50000');

T::ok(!submitPayment($uid, 3, '12345678')['ok'], 'دوره‌ی نامعتبر رد می‌شود');
T::ok(!submitPayment($uid, 1, 'ab')['ok'], 'کدِ خیلی کوتاه رد می‌شود');

$r = submitPayment($uid, 6, '12345678', 'کارت به کارت');
T::ok($r['ok'], 'پرداخت ثبت شد', $r['error'] ?? '');
$payId = (int)($r['id'] ?? 0);

// مبلغ باید از ضریبِ دوره حساب شود، نه از تعدادِ ماه
$st = $pdo->prepare('SELECT amount, months, status FROM payments WHERE id = :i');
$st->execute(['i' => $payId]);
$row = $st->fetch();
T::same(50000 * PLAN_PERIODS[6], (int)$row['amount'],
    'مبلغِ ۶ ماه با ضریبِ تخفیف حساب شده', 'ضریب: ' . PLAN_PERIODS[6]);
T::same('pending', $row['status'], 'وضعیت «در انتظار» است');

// ⛔ اعلامِ پرداخت به‌تنهایی نباید اشتراک بدهد
T::same(false, isPro($uid), '⛔ اعلامِ پرداخت به‌تنهایی کسی را Pro نمی‌کند');

// درخواستِ دوم تا وقتی اولی باز است پذیرفته نشود
T::ok(!submitPayment($uid, 1, '87654321')['ok'], 'پرداختِ دومِ در انتظار رد می‌شود');

// ---------------------------------------------------------------
T::group('تأیید و تمدید');

$adminId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
$ap = approvePayment($payId, $adminId ?: $uid);
T::ok($ap['ok'], 'پرداخت تأیید شد', $ap['error'] ?? '');
T::same(true, isPro($uid), 'کاربر Pro شد');

$first = $ap['until'];
$expected = (string)$pdo->query('SELECT DATE_ADD(CURDATE(), INTERVAL 6 MONTH)')->fetchColumn();
T::same($expected, $first, 'شش ماه از امروز');

// همان پرداخت دوباره تأیید نشود
T::ok(!approvePayment($payId, $adminId ?: $uid)['ok'],
    'پرداختِ تأییدشده دوباره تأیید نمی‌شود');

// ---------- تمدیدِ زودهنگام روزهای باقی‌مانده را نمی‌خورد ----------
// ⛔ مهم‌ترین بررسیِ این فایل.
$r2 = submitPayment($uid, 1, '11112222');
T::ok($r2['ok'], 'پرداختِ تمدید ثبت شد', $r2['error'] ?? '');
$ap2 = approvePayment((int)$r2['id'], $adminId ?: $uid);
T::ok($ap2['ok'], 'تمدید تأیید شد');

$expected2 = (string)$pdo->query(
    'SELECT DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 6 MONTH), INTERVAL 1 MONTH)'
)->fetchColumn();
T::same($expected2, $ap2['until'],
    '⛔ تمدید از انقضای فعلی ادامه پیدا می‌کند، نه از امروز');

// ---------------------------------------------------------------
T::group('انقضا خودش اتفاق می‌افتد');

// تاریخ را به دیروز می‌بریم — بدون هیچ کارِ زمان‌بندی‌شده‌ای
$pdo->prepare('UPDATE users SET pro_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = :u')
    ->execute(['u' => $uid]);
T::same(false, isPro($uid), '⛔ با گذشتنِ تاریخ، خودبه‌خود Pro نیست');

$pdo->prepare('UPDATE users SET pro_until = CURDATE() WHERE id = :u')->execute(['u' => $uid]);
T::same(true, isPro($uid), 'روزِ آخر هنوز فعال است');

// ---------------------------------------------------------------
T::group('رد کردن');

$r3 = submitPayment($uid, 1, '33334444');
$before = userPlan($uid)['pro_until'];
T::ok(rejectPayment((int)$r3['id'], $adminId ?: $uid, 'واریز پیدا نشد'), 'پرداخت رد شد');
T::same($before, userPlan($uid)['pro_until'], 'ردِ پرداخت به اشتراک دست نمی‌زند');

$st = $pdo->prepare('SELECT status, note FROM payments WHERE id = :i');
$st->execute(['i' => (int)$r3['id']]);
$rej = $st->fetch();
T::same('rejected', $rej['status'], 'وضعیت «رد شد» است');
T::ok(str_contains((string)$rej['note'], 'واریز پیدا نشد'), 'دلیلِ رد ثبت شد');

// ⛔ ردیف پاک نمی‌شود — تاریخچه‌ی مالی باید بماند
$st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE user_id = :u');
$st->execute(['u' => $uid]);
T::same(3, (int)$st->fetchColumn(), '⛔ هیچ پرداختی حذف نمی‌شود، فقط وضعیتش عوض می‌شود');

exit(T::report());
