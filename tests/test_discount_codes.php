<?php
/**
 * تستِ کد تخفیف و سقفِ «n نفر اول».
 *
 * ⛔ چهار چیزی که اگر بشکنند بی‌صدا پول یا اعتبار می‌برند:
 *
 *  ۱. **سقف باید دقیق باشد.** «۱۰ نفر اول» یعنی ۱۰ نفر. اگر شمارنده
 *     اتمی نباشد یا شرطِ سقف از `UPDATE` بیفتد، کد بی‌سروصدا به ۱۱ و
 *     ۱۲ نفر هم می‌رسد و مالکِ نصب فقط ماه‌ها بعد از روی صورت‌حساب
 *     می‌فهمد.
 *
 *  ۲. **کدِ تخفیفِ خرید نباید دسترسیِ رایگان بدهد.** اگر نگهبانِ
 *     `discountIsFree()` از `redeemDiscountCode()` بیفتد، کدِ «۲۰٪
 *     تخفیف» به یک کدِ «رایگان» تبدیل می‌شود — بدترین شکلِ خرابی،
 *     چون هیچ خطایی نمی‌دهد و کاربر هم شکایتی نمی‌کند.
 *
 *  ۳. **ردِ پرداخت باید ظرفیت را پس بدهد.** وگرنه کدی با ۱۰ ظرفیت با
 *     ۱۰ پرداختِ ردشده تمام می‌شود، بی‌آنکه حتی یک نفر چیزی گرفته
 *     باشد.
 *
 *  ۴. **یک کاربر، یک بار.** بدونِ آن، همان یک نفر می‌تواند کدِ
 *     «مادام‌العمر» را ده بار بزند.
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
    T::group('کد تخفیف');
    T::skip('تست کد تخفیف', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plan.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('کد تخفیف');
    T::skip('تست کد تخفیف', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

T::group('کد تخفیف');

if (!discountCodesAvailable()) {
    T::skip('تست کد تخفیف', 'migration_discount_codes اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
// قیمت را ثابت می‌کنیم تا حسابِ تخفیف قابل سنجش باشد، و آخرِ کار
// دست‌نخورده برش می‌گردانیم.
$origPrice = getSetting(PLAN_PRICE_SETTING, '0');
setSetting(PLAN_PRICE_SETTING, '100000');

$CODES = ['__TFREE', '__TPART', '__TCAP', '__TEXPIRED', '__TOFF', '__TLIFE'];
$USERS = ['__test_dc_a', '__test_dc_b', '__test_dc_c'];

$cleanup = function () use ($pdo, $CODES, $USERS) {
    foreach ($USERS as $u) {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $st->execute(['u' => $u]);
        if ($id = $st->fetchColumn()) {
            foreach (['payments', 'notifications', 'wallets'] as $t) {
                try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
                catch (PDOException $e) { /* جدول شاید نباشد */ }
            }
        }
        $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $u]);
    }
    foreach ($CODES as $c) {
        $pdo->prepare('DELETE FROM discount_codes WHERE code = :c')->execute(['c' => $c]);
    }
};
$cleanup();
register_shutdown_function(function () use ($cleanup, $origPrice) {
    $cleanup();
    setSetting(PLAN_PRICE_SETTING, $origPrice);
});

/** یک کاربر می‌سازد و شناسه‌اش را برمی‌گرداند. */
$mkUser = function (string $name) use ($pdo): int {
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role, is_active)
         VALUES (:u, :p, 'کاربر تست کد', 'user', 1)"
    )->execute(['u' => $name, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};

/** یک کد می‌سازد. */
$mkCode = function (string $code, int $percent, int $months, int $maxUses,
                    ?string $expires = null, int $active = 1) use ($pdo): void {
    $pdo->prepare(
        'INSERT INTO discount_codes (code, percent, months, max_uses, expires_at, is_active)
         VALUES (:c, :p, :m, :x, :e, :a)'
    )->execute(['c' => $code, 'p' => $percent, 'm' => $months,
                'x' => $maxUses, 'e' => $expires, 'a' => $active]);
};

$usedCount = function (string $code) use ($pdo): int {
    $st = $pdo->prepare('SELECT used_count FROM discount_codes WHERE code = :c');
    $st->execute(['c' => $code]);
    return (int)$st->fetchColumn();
};

$proUntil = function (int $uid) use ($pdo): ?string {
    $st = $pdo->prepare('SELECT pro_until FROM users WHERE id = :u');
    $st->execute(['u' => $uid]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
};

$uidA = $mkUser($USERS[0]);
$uidB = $mkUser($USERS[1]);
$uidC = $mkUser($USERS[2]);

// ---------------------------------------------------------------
T::group('۱. نرمال‌سازی — همان کدی که مدیر ثبت کرده باید پیدا شود');

T::same('NOWRUZ', normalizeDiscountCode('  nowruz '), 'فاصله و حروف کوچک');
T::same('SALE100', normalizeDiscountCode('sale۱۰۰'), '⛔ ارقام فارسی هم لاتین می‌شوند');
T::same('A-B_C', normalizeDiscountCode('a-b_c'), 'خط تیره و زیرخط می‌مانند');
T::same('ABC', normalizeDiscountCode('a b!c'), 'کاراکترِ بی‌ربط حذف می‌شود');
T::same('', normalizeDiscountCode('   '), 'ورودی خالی، خروجی خالی');
T::same(DISCOUNT_CODE_MAX_LEN, mb_strlen(normalizeDiscountCode(str_repeat('A', 90))),
    'طول به سقفِ ستون بریده می‌شود');

// ---------------------------------------------------------------
T::group('۲. وضعیتِ کد — تنها جای این تصمیم');

$mkCode('__TOFF', 100, 1, 0, null, 0);
$mkCode('__TEXPIRED', 100, 1, 0, date('Y-m-d', strtotime('-1 day')), 1);

T::same('off', discountCodeState(findDiscountCode('__TOFF')), 'کدِ خاموش');
T::same('expired', discountCodeState(findDiscountCode('__TEXPIRED')), '⛔ کدِ منقضی');
T::same('used_up', discountCodeState(['is_active' => 1, 'expires_at' => null,
    'max_uses' => 3, 'used_count' => 3]), '⛔ ظرفیتِ تمام‌شده');
T::same('active', discountCodeState(['is_active' => 1, 'expires_at' => null,
    'max_uses' => 3, 'used_count' => 2]), 'کدِ سالم');
T::same('active', discountCodeState(['is_active' => 1, 'expires_at' => null,
    'max_uses' => 0, 'used_count' => 999]), 'سقفِ صفر یعنی بی‌نهایت');

$r = discountCheck('__TOFF', $uidA);
T::same(false, $r['ok'], 'کدِ خاموش پذیرفته نمی‌شود');
$r = discountCheck('__TEXPIRED', $uidA);
T::same(false, $r['ok'], 'کدِ منقضی پذیرفته نمی‌شود');
$r = discountCheck('__TNOPE', $uidA);
T::same(false, $r['ok'], 'کدِ ناموجود پذیرفته نمی‌شود');

// ---------------------------------------------------------------
T::group('۳. کد ۱۰۰٪ — دسترسی کامل، همان لحظه');

$mkCode('__TFREE', 100, 6, 0);
$res = redeemDiscountCode($uidA, '__tfree');   // عمداً با حروف کوچک
T::same(true, $res['ok'], '⛔ کدِ ۱۰۰٪ همان لحظه اعمال می‌شود');
T::same(true, isPro($uidA), '⛔ کاربر بلافاصله Pro است — نه بعد از تأییدِ مدیر');
T::same(1, $usedCount('__TFREE'), 'شمارنده یکی بالا رفت');

$until = $proUntil($uidA);
$expect = (new DateTime())->modify('+6 months')->format('Y-m-d');
T::same($expect, $until, 'مدت دقیقاً همان چیزی است که روی کد نوشته شده');

// ردیفِ رد در `payments` — بدونش شش ماه بعد معلوم نیست چرا Pro است
$st = $pdo->prepare("SELECT method, amount, discount_code, status FROM payments
                     WHERE user_id = :u ORDER BY id DESC LIMIT 1");
$st->execute(['u' => $uidA]);
$pay = $st->fetch();
T::same('discount', $pay['method'] ?? '', 'ردِ کار در payments ثبت می‌شود');
T::same(0, (int)($pay['amount'] ?? -1), 'مبلغش صفر است');
T::same('__TFREE', $pay['discount_code'] ?? '', 'کد روی همان ردیف می‌ماند');
T::same('approved', $pay['status'] ?? '', 'تأییدشده ثبت می‌شود، نه در انتظار');

// ⛔ یک کاربر، یک بار
$again = redeemDiscountCode($uidA, '__TFREE');
T::same(false, $again['ok'], '⛔ همان کاربر بار دوم نمی‌تواند');
T::same(1, $usedCount('__TFREE'), 'و شمارنده هم دست نخورد');

// ---------------------------------------------------------------
T::group('۴. مادام‌العمر');

$mkCode('__TLIFE', 100, 0, 0);   // ۰ = مادام‌العمر
$res = redeemDiscountCode($uidB, '__TLIFE');
T::same(true, $res['ok'], 'کدِ مادام‌العمر اعمال می‌شود');
T::same(true, planIsForever($proUntil($uidB)), '⛔ ۰ ماه یعنی مادام‌العمر، نه «صفر ماه»');

// ---------------------------------------------------------------
T::group('۵. ⛔ سقفِ «n نفر اول» دقیق است');

$mkCode('__TCAP', 100, 1, 2);    // فقط دو نفر

T::same(true, redeemDiscountCode($uidA, '__TCAP')['ok'], 'نفر اول می‌گیرد');
T::same(true, redeemDiscountCode($uidB, '__TCAP')['ok'], 'نفر دوم می‌گیرد');

$third = redeemDiscountCode($uidC, '__TCAP');
T::same(false, $third['ok'], '⛔ نفر سوم نمی‌گیرد — سقف واقعاً می‌بندد');
T::same(2, $usedCount('__TCAP'), '⛔ شمارنده از سقف رد نمی‌شود');
T::same(false, isPro($uidC), 'و به نفر سوم هیچ دسترسی‌ای داده نشد');
T::same('used_up', discountCodeState(findDiscountCode('__TCAP')), 'وضعیتِ کد «ظرفیت تمام» شد');

// شرطِ سقف روی خودِ `UPDATE` هم هست — آخرین سد، حتی اگر مسیرِ تازه‌ای
// بدونِ سنجش صدایش بزند.
$pdo->beginTransaction();
T::same(false, consumeDiscountUse($pdo, (int)findDiscountCode('__TCAP')['id']),
    '⛔ خودِ دیتابیس هم بیش از سقف نمی‌دهد');
$pdo->rollBack();

// ---------------------------------------------------------------
T::group('۶. کدِ تخفیفِ خرید — حسابِ قیمت');

$mkCode('__TPART', 25, 1, 5);
$part = findDiscountCode('__TPART');

T::same(false, discountIsFree($part), 'کدِ ۲۵٪ رایگان نیست');

$q = discountFinalPrice($part, 1);
T::same(100000, $q['price'], 'قیمتِ پایه‌ی یک ماه');
T::same(25000, $q['discount'], 'یک‌چهارم تخفیف');
T::same(75000, $q['final'], 'مبلغِ نهایی');

$q6 = discountFinalPrice($part, 6);
T::same(500000, $q6['price'], 'شش ماه با ضریبِ خودش حساب می‌شود');
T::same(375000, $q6['final'], 'و تخفیف روی همان اعمال می‌شود');

$q0 = discountFinalPrice(null, 1);
T::same(0, $q0['discount'], 'بدونِ کد هیچ تخفیفی نیست');
T::same(100000, $q0['final'], 'و مبلغ همان قیمتِ پایه است');

// ⛔ مهم‌ترین نگهبانِ پولیِ این قابلیت
$bad = redeemDiscountCode($uidC, '__TPART');
T::same(false, $bad['ok'], '⛔ کدِ تخفیفِ خرید به‌تنهایی دسترسی نمی‌دهد');
T::same(false, isPro($uidC), 'و کاربر Pro نشد');
T::same(0, $usedCount('__TPART'), 'و ظرفیتی هم مصرف نشد');

// ---------------------------------------------------------------
T::group('۷. کد روی خرید — ثبت، و پس دادنِ ظرفیت هنگام رد');

$sub = submitPayment($uidC, 1, '12345678', '', '__tpart');
T::same(true, $sub['ok'], 'پرداخت با کد ثبت می‌شود');
T::same(75000, (int)($sub['amount'] ?? 0), '⛔ مبلغِ ثبت‌شده همان مبلغِ تخفیف‌خورده است');
T::same(1, $usedCount('__TPART'), 'ظرفیت رزرو شد');

$st = $pdo->prepare('SELECT discount_code, amount FROM payments WHERE id = :i');
$st->execute(['i' => $sub['id']]);
$row = $st->fetch();
T::same('__TPART', $row['discount_code'] ?? '', 'کد روی ردیفِ پرداخت می‌ماند');

// همان کاربر، همان کد، بارِ دوم
$dup = submitPayment($uidC, 1, '87654321', '', '__TPART');
T::same(false, $dup['ok'], 'یک کاربر دو بار از یک کد استفاده نمی‌کند');

T::same(true, rejectPayment((int)$sub['id'], $uidA, 'آزمایشی'), 'پرداخت رد می‌شود');
T::same(0, $usedCount('__TPART'), '⛔ ردِ پرداخت ظرفیت را پس می‌دهد');

// و حالا دوباره قابل استفاده است
$again = submitPayment($uidC, 1, '11223344', '', '__TPART');
T::same(true, $again['ok'], 'بعد از پس دادنِ ظرفیت، دوباره کار می‌کند');
T::same(1, $usedCount('__TPART'), 'و شمارنده درست است');

// ---------------------------------------------------------------
T::group('۸. ساختِ کد — اعتبارسنجی');

$bad = saveDiscountCode(['code' => 'ab', 'percent' => 100, 'months' => 1], 0);
T::same(false, $bad['ok'], 'کدِ کوتاه رد می‌شود');

$bad = saveDiscountCode(['code' => '__TNEW', 'percent' => 0, 'months' => 1], 0);
T::same(false, $bad['ok'], 'درصدِ صفر رد می‌شود');

$bad = saveDiscountCode(['code' => '__TNEW', 'percent' => 101, 'months' => 1], 0);
T::same(false, $bad['ok'], 'درصدِ بیش از ۱۰۰ رد می‌شود');

$bad = saveDiscountCode(['code' => '__TNEW', 'percent' => 50, 'months' => 7], 0);
T::same(false, $bad['ok'], '⛔ مدتِ خارج از PLAN_GRANT_PERIODS رد می‌شود');

$bad = saveDiscountCode(['code' => '__TNEW', 'percent' => 50, 'months' => 1,
                         'expires_at' => '۱۴۰۳/۰۸/۳۱'], 0);
T::same(false, $bad['ok'], '⛔ ۳۱ آبان وجود ندارد و رد می‌شود');

// ⛔ ویرایشِ یک کد نباید شمارنده‌اش را صفر کند
$ok = saveDiscountCode(['code' => '__tpart', 'percent' => 30, 'months' => 1,
                        'max_uses' => 5, 'note' => 'ویرایش شد'], 0);
T::same(true, $ok['ok'], 'ویرایشِ کدِ موجود انجام می‌شود');
T::same(1, $usedCount('__TPART'), '⛔ ویرایش شمارنده را صفر نمی‌کند');
T::same(30, (int)findDiscountCode('__TPART')['percent'], 'و مقدارِ تازه نشست');

// ---------------------------------------------------------------
T::group('۹. تاریخِ شمسیِ تایپ‌شده');

T::same('2025-03-20', jalaliStringToGregorian('1403/12/30'), 'کبیسه‌ی ۱۴۰۳');
T::same('2025-03-20', jalaliStringToGregorian('۱۴۰۳/۱۲/۳۰'), 'با ارقام فارسی');
T::same(null, jalaliStringToGregorian('1403/08/31'), '⛔ ۳۱ آبان وجود ندارد');
T::same(null, jalaliStringToGregorian('1403/13/01'), 'ماهِ ۱۳ وجود ندارد');
T::same(null, jalaliStringToGregorian(''), 'ورودی خالی');
T::same(null, jalaliStringToGregorian('چهارشنبه'), 'ورودی بی‌ربط');

// ---------------------------------------------------------------
T::group('۱۰. ساختِ خودکارِ کد');

$gen = generateDiscountCode('HESAB');
T::ok(str_starts_with($gen, 'HESAB-'), 'پیشوند سرِ جایش است', $gen);
T::same($gen, normalizeDiscountCode($gen), 'کدِ ساخته‌شده از نرمال‌سازی رد می‌شود');
T::ok(!preg_match('/[O0I1]/', substr($gen, 6)), '⚠ حرف‌های اشتباه‌گیر در آن نیست', $gen);
T::ok(generateDiscountCode() !== generateDiscountCode(), 'دو بار صدا زدن دو کد می‌دهد');

exit(T::report());
