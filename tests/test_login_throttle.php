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
    T::skip('تست سد حدس رمز', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('سد حدس رمز');
    T::skip('تست سد حدس رمز', 'اتصال به دیتابیس برقرار نشد');
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
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $USER]);
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
$cleanup();
exit(T::report());
