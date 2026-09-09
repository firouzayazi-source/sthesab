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

forgetSetting(PLAN_ENFORCE_SETTING);
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

// ⚠ فهرستِ پولی‌ها به خواستِ مالکِ نصب عوض شد: چک، طلب و بدهی و
//   تراکنشِ دوره‌ای هم به آن اضافه شدند. تست همان تصمیم را می‌سنجد،
//   نه سلیقه‌ی قبلی.
foreach (['trades', 'cheques', 'debts', 'recurring', 'api', 'reminders'] as $f) {
    T::same(false, planAllows($uid, $f), "با اجرای روشن، «{$f}» برای رایگان بسته است");
}

// ⛔ و این نیمه‌ی دیگرِ همان تصمیم است و مهم‌تر از آن: هسته‌ی **ثبتِ
//    پول** هرگز بسته نمی‌شود. اگر کاربرِ رایگان نتواند خرجش را ثبت
//    کند، اپ برایش بی‌فایده است و اصلاً امتحانش نمی‌کند — یعنی هیچ‌وقت
//    به خریدن هم نمی‌رسد.
foreach (['transactions', 'wallets', 'categories', 'budget',
          'savings', 'assets', 'reports'] as $f) {
    T::same(true, planAllows($uid, $f), "⛔ هسته‌ی اپ («{$f}») باز می‌ماند");
}
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

// ---------------------------------------------------------------
// ⛔ هدیه‌ی مدیر: دسترسی کامل بدون پرداخت.
//
//    خطرِ اصلی اینجا **دو نسخه شدنِ حسابِ تاریخ** است: اگر هدیه از
//    `planExtendUntil()` رد نشود، دیر یا زود با تأییدِ پرداخت فرق
//    می‌کند و تفاوتش فقط در عددِ روزهای کاربر دیده می‌شود — یعنی
//    بی‌صدا. پس اینجا هم همان قاعده‌ی «از انقضای فعلی، نه از امروز»
//    سنجیده می‌شود.
T::group('⛔ دسترسی رایگان توسط مدیر');

// ⚠ کاربرِ جدا، چون `$uid` از بخش‌های بالا `pro_until` دارد و آن‌وقت
//   «سه ماه از امروز» را نمی‌شد سنجید.
$GRANTU = '__plan_grant_test';
$pdo->prepare('DELETE FROM payments WHERE user_id IN (SELECT id FROM users WHERE username = :u)')
    ->execute(['u' => $GRANTU]);
$pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $GRANTU]);
$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست هدیه', 'user', 1)"
)->execute(['u' => $GRANTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$gid = (int)$pdo->lastInsertId();

$g1 = grantPro($gid, 3, $adminId ?: $gid);
T::ok(!empty($g1['ok']), 'هدیه‌ی سه‌ماهه ثبت شد', $g1['error'] ?? '');
$exp3 = (new DateTime('today'))->modify('+3 month')->format('Y-m-d');
T::same($exp3, userPlan($gid)['pro_until'], 'سه ماه از امروز');
T::ok(userPlan($gid)['is_pro'], 'کاربر حالا Pro است');

grantPro($gid, 2, $adminId ?: $gid);
$exp5 = (new DateTime('today'))->modify('+3 month')->modify('+2 month')->format('Y-m-d');
T::same($exp5, userPlan($gid)['pro_until'],
    '⛔ تمدید از انقضای فعلی جلو می‌رود، نه از امروز — وگرنه کاربرِ خوش‌حساب روزهایش را از دست می‌داد');

grantPro($gid, 0, $adminId ?: $gid);
$pf = userPlan($gid);
T::same(PLAN_FOREVER_DATE, $pf['pro_until'], '⛔ «مادام‌العمر» یک تاریخِ دور است، نه صفر ماه');
T::ok($pf['is_forever'], 'به‌عنوان بی‌پایان شناخته می‌شود');
T::ok($pf['is_pro'], 'و البته Pro است');
T::same(null, $pf['days_left'],
    '⛔ «روزهای باقی‌مانده» برای مادام‌العمر خالی است، نه سه میلیون روز');

// ⛔ خرابیِ واقعی که آزمونِ جهش پیدا کرد: `DATE_ADD('9999-12-31', …)`
//    از بازه‌ی `DATE` بیرون می‌زند و MySQL **NULL** برمی‌گرداند، نه خطا.
//    یعنی مدیری که به کاربرِ مادام‌العمر اشتباهاً «یک ماهه» هم می‌داد،
//    دسترسیِ او را کاملاً پاک می‌کرد و پیامِ «فعال شد» هم می‌گرفت.
grantPro($gid, 1, $adminId ?: $gid);
$after = userPlan($gid);
T::same(PLAN_FOREVER_DATE, $after['pro_until'],
    '⛔ تمدیدِ یک اشتراکِ مادام‌العمر آن را پاک نمی‌کند');
T::ok($after['is_pro'], 'و کاربر همچنان Pro است');

// ⚠ اعتبارسنجیِ دوره روی کاربرِ **تازه** سنجیده می‌شود، نه روی همین یکی.
//   نسخه‌ی اولِ این تست بعد از هدیه‌ی مادام‌العمر اجرا می‌شد و سبز بود —
//   ولی به دلیلِ همان سرریزِ تاریخ، نه به دلیلِ خودِ اعتبارسنجی. یعنی
//   با برداشتنِ کاملِ اعتبارسنجی هم سبز می‌ماند: تستی که چیزی را
//   می‌سنجید که فکر می‌کردیم.
$pdo->prepare("UPDATE users SET plan = 'free', pro_until = NULL WHERE id = :u")->execute(['u' => $gid]);
$bad = grantPro($gid, 7, $adminId ?: $gid);
T::ok(empty($bad['ok']), '⛔ دوره‌ای که در PLAN_GRANT_PERIODS نیست پذیرفته نمی‌شود');
T::same(null, userPlan($gid)['pro_until'], 'و هیچ چیزی هم روی کاربر ننشست');

// دوباره مادام‌العمر، تا شمارشِ تاریخچه‌ی پایین سرِ جایش بماند.
grantPro($gid, 0, $adminId ?: $gid);

$ghost = grantPro(0, 1, $adminId ?: $gid);
T::ok(empty($ghost['ok']), 'کاربرِ ناموجود ردیفِ پرداختِ یتیم نمی‌سازد');

// ⛔ ردِ کار باید بماند: شش ماه بعد باید معلوم باشد چرا این کاربر Pro است.
$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = :u AND method = 'admin_grant'");
$st->execute(['u' => $gid]);
T::same(5, (int)$st->fetchColumn(), '⛔ هر هدیه یک ردیف در تاریخچه گذاشت');

$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = :u AND amount <> 0");
$st->execute(['u' => $gid]);
T::same(0, (int)$st->fetchColumn(), 'و مبلغش صفر است — این پرداخت نیست');

T::ok(revokePro($gid, $adminId ?: $gid)['ok'], 'پس گرفتن انجام شد');
$pr = userPlan($gid);
T::same(null, $pr['pro_until'], '⛔ بعد از پس گرفتن، انقضا خالی است');
T::ok(!$pr['is_pro'], 'و کاربر دیگر Pro نیست');

$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = :u");
$st->execute(['u' => $gid]);
T::same(6, (int)$st->fetchColumn(),
    '⛔ پس گرفتن هم تاریخچه را پاک نمی‌کند، فقط یک ردیف به آن اضافه می‌کند');

$pdo->prepare('DELETE FROM payments WHERE user_id = :u')->execute(['u' => $gid]);
$pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $gid]);

exit(T::report());
