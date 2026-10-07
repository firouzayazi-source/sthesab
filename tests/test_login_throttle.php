<?php
/**
 * تست سدِ حدس رمز.
 *
 * چیزی که این تست نگه می‌دارد: پیش از این، صفحه‌ی ورود هیچ محدودیتی
 * نداشت و می‌شد بی‌نهایت رمز امتحان کرد. اگر روزی این سد بی‌سروصدا
 * برداشته شود (مثلاً کسی ترتیب فراخوانی‌ها را عوض کند)، هیچ خطایی
 * دیده نمی‌شود و اپ ظاهراً درست کار می‌کند — دقیقاً همان جور خرابی‌ای
 * که فقط تست می‌گیرد.
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
    T::group('سد حدس رمز');
    T::blocked('تست سد حدس رمز', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('سد حدس رمز');
    T::blocked('تست سد حدس رمز', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!LoginThrottle::available()) {
    T::group('سد حدس رمز');
    T::skip('تست سد حدس رمز', 'migration_login_throttle اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
$USER = '__test_throttle';
$PASS = 'CorrectHorse12345';
$IP   = '203.0.113.77';          // بازه‌ی رزروشده‌ی مستندسازی — با هیچ IP واقعی برخورد نمی‌کند
$IP2  = '203.0.113.78';

$cleanup = function () use ($pdo, $USER, $IP, $IP2) {
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried LIKE :m OR request_ip IN (:a, :b)')
        ->execute(['m' => '__test_throttle%', 'a' => $IP, 'b' => $IP2]);
    $pdo->prepare('DELETE FROM users WHERE username IN (:u, :a)')
        ->execute(['u' => $USER, 'a' => '__test_throttle_acct']);
    $pdo->prepare("DELETE FROM login_attempts WHERE request_ip LIKE '203.0.113.1__'")->execute();
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست سد', 'user', 1)"
)->execute(['u' => $USER, 'p' => password_hash($PASS, PASSWORD_DEFAULT)]);

// نشست لازم است چون attemptLogin در حالت موفق session_regenerate_id می‌زند.
// بافر خروجی هم لازم است: بدون آن، چاپِ عنوانِ گروه‌ها «هدر ارسال‌شده»
// حساب می‌شود و session_regenerate_id هشدار می‌دهد — هشداری که به کدِ
// اپ ربط ندارد و فقط خروجی تست را کثیف می‌کند.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
ob_start();

// ---------------------------------------------------------------
T::group('شمارش تلاش ناموفق روی نام کاربری');

$msgs = [];
for ($i = 1; $i <= LoginThrottle::MAX_PER_USER; $i++) {
    $r = Auth::attemptLogin($USER, 'رمزغلط', $IP);
    $msgs[$i] = $r;
}

T::ok(
    empty($msgs[1]['locked']),
    'تلاش اول قفل نمی‌کند',
    $msgs[1]['message'] ?? ''
);
T::ok(
    empty($msgs[LoginThrottle::MAX_PER_USER]['locked']),
    'تلاش پنجم هنوز خودش قفل نیست (سقف با تلاش بعدی می‌خورد)'
);

// همه‌ی پیام‌های ناموفقِ پیش از قفل باید یکی باشند تا وجود حساب لو نرود
$distinct = array_unique(array_column($msgs, 'message'));
T::same(1, count($distinct), 'پیام همه‌ی تلاش‌های ناموفق یکسان است');

// ---------------------------------------------------------------
T::group('پس از رسیدن به سقف، قفل می‌شود');

$blocked = Auth::attemptLogin($USER, 'رمزغلط', $IP);
T::ok(!empty($blocked['locked']), 'تلاش ششم قفل است');
T::ok(!$blocked['success'],       'تلاش قفل‌شده موفق نیست');

// مهم‌ترین بخش: رمزِ *درست* هم در حالت قفل رد می‌شود، وگرنه سد
// بی‌فایده است — مهاجم فقط باید رمز درست را حدس بزند.
$rightPass = Auth::attemptLogin($USER, $PASS, $IP);
T::ok(!$rightPass['success'],       'حتی با رمز درست هم در حالت قفل وارد نمی‌شود');
T::ok(!empty($rightPass['locked']), 'و دلیلش قفل بودن است، نه اشتباه بودن رمز');

// ---------------------------------------------------------------
T::group('قفلِ یک حساب، حساب دیگر را نمی‌بندد');

// اگر قفل فقط روی IP بود، این هم بسته می‌شد و مهاجم می‌توانست با تلاش
// عمدی حسابِ یک نفر دیگر را از کار بیندازد.
$other = Auth::attemptLogin('__test_throttle_other', 'رمزغلط', $IP2);
T::ok(empty($other['locked']), 'نام کاربری دیگر از IP دیگر باز است');

// ---------------------------------------------------------------
T::group('ورود موفق شمارنده را صفر می‌کند');

$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :u')
    ->execute(['u' => mb_strtolower($USER)]);      // قفل را دستی باز می‌کنیم

// چهار تلاش ناموفق (زیر سقف)، بعد یک ورود موفق
for ($i = 0; $i < LoginThrottle::MAX_PER_USER - 1; $i++) {
    Auth::attemptLogin($USER, 'رمزغلط', $IP);
}
$ok = Auth::attemptLogin($USER, $PASS, $IP);
T::ok($ok['success'], 'با رمز درست و زیر سقف، ورود انجام می‌شود');

$st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username_tried = :u');
$st->execute(['u' => mb_strtolower($USER)]);
T::same(0, (int)$st->fetchColumn(), 'ورود موفق سابقه‌ی همان نام کاربری را پاک می‌کند');

// ---------------------------------------------------------------
T::group('نامِ ناموجود هم شمرده می‌شود');

// وگرنه مهاجم با نام‌های تصادفی بی‌نهایت درخواست می‌زند و هیچ‌کدام
// شمرده نمی‌شود.
$ghost = '__test_throttle_ghost';
for ($i = 0; $i < LoginThrottle::MAX_PER_USER; $i++) {
    Auth::attemptLogin($ghost, 'هرچی', $IP2);
}
$st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username_tried = :u');
$st->execute(['u' => $ghost]);
T::same(LoginThrottle::MAX_PER_USER, (int)$st->fetchColumn(), 'تلاش روی نام ناموجود ثبت می‌شود');
T::ok(!empty(Auth::attemptLogin($ghost, 'هرچی', $IP2)['locked']), 'نام ناموجود هم قفل می‌شود');

// ---------------------------------------------------------------
T::group('تلاش‌های قدیمی از پنجره بیرون می‌روند');

$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :u')->execute(['u' => mb_strtolower($USER)]);
$w = LoginThrottle::WINDOW_MIN;
for ($i = 0; $i < LoginThrottle::MAX_PER_USER; $i++) {
    // زمان در دیتابیس ساخته می‌شود، نه در PHP — همان قاعده‌ای که یک بار
    // در بازیابی رمز شکست و لینک بلافاصله «منقضی» می‌شد.
    $pdo->prepare(
        "INSERT INTO login_attempts (username_tried, request_ip, created_at)
         VALUES (:u, :ip, NOW() - INTERVAL ($w + 1) MINUTE)"
    )->execute(['u' => mb_strtolower($USER), 'ip' => $IP]);
}
T::same(null, LoginThrottle::lockedFor($USER, $IP), 'تلاش‌های کهنه دیگر قفل نمی‌کنند');

$pruned = LoginThrottle::prune();
T::ok($pruned >= LoginThrottle::MAX_PER_USER, 'prune ردیف‌های کهنه را پاک می‌کند', "پاک‌شده: {$pruned}");

// ---------------------------------------------------------------
T::group('⛔ شمارشِ تلاش برای پنل مدیر');

/*
 * سد بین کاربرِ واقعی و مهاجم فرق نمی‌گذارد، پس کاربری که رمزش را چند
 * بار غلط زده تا پایانِ پنجره بیرون می‌ماند. تا امروز تنها راهِ باز
 * کردنش `deploy/user-admin.php --unlock` از راهِ SSH بود — یعنی مالکِ
 * نصبی که SSH ندارد اصلاً راهی نداشت. پنل مدیر حالا دکمه‌اش را دارد،
 * ولی **فقط کنارِ کاربری که واقعاً قفل است**؛ همین شمارش آن را
 * تصمیم می‌گیرد.
 */
$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :u')
    ->execute(['u' => mb_strtolower($USER)]);

T::same(0, LoginThrottle::failureCounts()[LoginThrottle::key($USER)] ?? 0,
    'کاربرِ سالم هیچ نشانی نمی‌گیرد');

LoginThrottle::recordFailure($USER, $IP);
LoginThrottle::recordFailure($USER, $IP);
T::same(2, LoginThrottle::failureCounts()[LoginThrottle::key($USER)] ?? 0,
    'دو تلاشِ ناموفق شمرده می‌شود');

// ⚠ کلید همان‌طور نرمال می‌شود که هنگام شمردن؛ وگرنه نشان کنارِ
//   کاربری با نامِ بزرگ‌حرف هرگز دیده نمی‌شد.
T::same(2, LoginThrottle::failureCounts()[LoginThrottle::key(mb_strtoupper($USER))] ?? 0,
    '⛔ نرمال‌سازیِ کلید با شمارش یکی است');

LoginThrottle::clear($USER);
T::same(0, LoginThrottle::failureCounts()[LoginThrottle::key($USER)] ?? 0,
    '⛔ «باز کردن قفل» شمارنده را واقعاً صفر می‌کند');

// ---------------------------------------------------------------
T::group('⛔ شکل‌های دیگرِ همان شناسه شمارنده‌ی جدا ندارند');

/*
 * خرابیِ واقعی (بازبینی): جست‌وجوی کاربر شماره را با
 * `SmsLogin::normalizePhone()` نرمال می‌کرد ولی شمارنده روی رشته‌ی خامِ
 * تایپ‌شده بود. پس `0912-999-8877`، `+98912…`، ارقامِ فارسی، نامِ کاربری و
 * ایمیلِ همان حساب هر کدام ۵ حدسِ جدا داشتند — ۴۰ حدس به‌جای ۵ روی یک
 * حساب. هر تلاش از IPِ جدا می‌آید تا سقفِ IP چیزی را پنهان نکند.
 */
if (!tableHasColumn('users', 'phone') || !tableHasColumn('users', 'email')) {
    T::skip('شمارنده‌ی مشترکِ شکل‌ها', 'ستون phone یا email هنوز نیست');
} else {
    $ACCT  = '__test_throttle_acct';
    $PHONE = '0912' . random_int(1000000, 9999999);
    $MAIL  = '__test_throttle_' . random_int(1000, 9999) . '@example.test';
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $ACCT]);
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role, is_active, phone, email)
         VALUES (:u, :p, 'کاربر تست شکل‌ها', 'user', 1, :ph, :m)"
    )->execute(['u' => $ACCT, 'p' => password_hash($PASS, PASSWORD_DEFAULT), 'ph' => $PHONE, 'm' => $MAIL]);

    $fa = strtr($PHONE, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                         '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    $variants = [
        '+98' . substr($PHONE, 1),
        substr($PHONE, 0, 4) . '-' . substr($PHONE, 4, 3) . '-' . substr($PHONE, 7),
        $fa,
        mb_strtoupper($MAIL),
        mb_strtoupper($ACCT),
    ];
    $n = 0;
    foreach ($variants as $v) {
        Auth::verifyCredentials($v, 'رمزغلط', '203.0.113.' . (100 + $n));
        $n++;
    }
    T::same(LoginThrottle::MAX_PER_USER, $n, 'به اندازه‌ی سقفِ یک حساب، هر بار با شکلی دیگر');

    // تلاشِ بعدی با هر شکلی — حتی با رمزِ درست و از IPِ تازه — قفل است.
    $after = Auth::verifyCredentials($PHONE, $PASS, '203.0.113.120');
    T::ok(!empty($after['locked']) && !$after['success'],
        '⛔ شکل‌های مختلفِ یک حساب یک شمارنده دارند (شماره، +98، خط‌تیره، فارسی، ایمیل، نام)',
        $after['message'] ?? '');

    // و «باز کردن قفل» در پنل مدیر (با نامِ کاربری) همان شمارنده را صفر می‌کند.
    LoginThrottle::clear($ACCT);
    $ok = Auth::verifyCredentials('+98' . substr($PHONE, 1), $PASS, '203.0.113.121');
    T::ok($ok['success'] ?? false, 'بازکردنِ قفل با نامِ کاربری، شکلِ +98 را هم باز می‌کند',
        $ok['message'] ?? '');

    // شماره‌ی **ناموجود** هم با شکلِ نرمالش شمرده می‌شود — و پیامش همان است.
    $ghostPhone = '0912' . random_int(1000000, 9999999);
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :u')->execute(['u' => $ghostPhone]);
    $gmsgs = [];
    for ($i = 0; $i < LoginThrottle::MAX_PER_USER; $i++) {
        $gv = $i % 2 ? '+98' . substr($ghostPhone, 1) : substr($ghostPhone, 0, 4) . ' ' . substr($ghostPhone, 4);
        $gmsgs[] = Auth::verifyCredentials($gv, 'رمزغلط', '203.0.113.' . (130 + $i))['message'];
    }
    T::ok(!empty(Auth::verifyCredentials($ghostPhone, 'رمزغلط', '203.0.113.140')['locked']),
        '⛔ شکل‌های یک شماره‌ی ناموجود هم یک شمارنده دارند');
    T::same(['نام کاربری یا رمز عبور اشتباه است.'], array_values(array_unique($gmsgs)),
        'پیامِ شناسه‌ی ناموجود همان پیامِ همیشگی است (وجودِ حساب لو نمی‌رود)');

    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried IN (:a, :b)')
        ->execute(['a' => mb_strtolower($ACCT), 'b' => $ghostPhone]);
    $pdo->prepare("DELETE FROM login_attempts WHERE request_ip LIKE '203.0.113.1__'")->execute();
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $ACCT]);
}

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
