<?php
/**
 * تست «ماندن در حساب» — دستگاه مورد اعتماد، مهلت، و کشویی بودنش.
 *
 * چرا این تست هست: این قابلیت **دو بار** بی‌سروصدا شکسته است.
 *
 *   بار اول — `isLoggedIn()` هنگام انقضای مهلت `logout()` را صدا می‌زد،
 *   و `logout()` ردیف `trusted_devices` را پاک می‌کرد. یعنی همان مهلتی
 *   که قرار بود این قابلیت از رویش پل بزند، خودِ آن را نابود می‌کرد.
 *
 *   بار دوم — مهلت **ثابت** بود نه کشویی: `loginFromTrustedDevice()`
 *   فقط `last_used_at` را به‌روز می‌کرد و `expires_at` را دست نمی‌زد.
 *   کاربری که هر روز اپ را باز می‌کرد هم سرِ سررسیدِ اول بیرون می‌افتاد.
 *
 * هیچ‌کدام خطایی نمی‌دادند؛ فقط کاربر رمز می‌خواست.
 *
 * چیزهایی که با هم باید درست بمانند:
 *   ۱. انقضای مهلت + دستگاه مورد اعتماد   → وارد بماند، اعتماد سالم
 *   ۲. **کشویی** — سررسید از نو کامل شود
 *   ۳. خروجِ خواسته‌ی کاربر                → اعتماد باطل شود
 *   ۴. انقضای مهلت بدون دستگاه مورد اعتماد → بیرون بیفتد
 *   ۵. «بدون مهلت» (۰)                     → هرگز منقضی نشود
 *   ۶. مهلت کوتاه (۱ دقیقه)                → واقعاً منقضی شود
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
    T::group('اعتماد دستگاه');
    T::blocked('تست اعتماد دستگاه', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('اعتماد دستگاه');
    T::blocked('تست اعتماد دستگاه', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('trusted_devices')) {
    T::group('اعتماد دستگاه');
    T::skip('تست اعتماد دستگاه', 'جدول trusted_devices وجود ندارد');
    exit(T::report());
}

$hasWindow = tableHasColumn('users', 'session_minutes');

// ---------------------------------------------------------------
$USER = '__test_trust';

$cleanup = function () use ($pdo, $USER) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $USER]);
    if ($id = $st->fetchColumn()) {
        $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :u')->execute(['u' => $id]);
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $USER]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست اعتماد', 'user', 1)"
)->execute(['u' => $USER, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

/** مهلتِ کاربر را در دیتابیس می‌گذارد (۰ = بدون مهلت). */
$setWindow = function (int $minutes) use ($pdo, $userId, $hasWindow): void {
    if (!$hasWindow) { return; }
    $pdo->prepare('UPDATE users SET session_minutes = :m WHERE id = :id')
        ->execute(['m' => $minutes, 'id' => $userId]);
};
$setWindow(60);

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
ob_start();   // تا setcookie و session_regenerate_id هشدار «هدر ارسال شد» ندهند

/**
 * یک دستگاه مورد اعتماد می‌سازد و کوکی‌اش را در $_COOKIE می‌گذارد.
 *
 * سررسید عمداً **نزدیک** گذاشته می‌شود تا کشویی شدن قابل اندازه‌گیری
 * باشد: اگر سررسید از اول ۳۰ روز بود، تفاوتِ «تمدید شد» و «نشد» در
 * نویزِ چند ثانیه گم می‌شد.
 */
$trustDevice = function (int $daysLeft = 2) use ($pdo, $userId): void {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at, last_used_at)
         VALUES (:u, :s, :h, :l, DATE_ADD(NOW(), INTERVAL :d DAY), NOW())'
    )->execute([
        'u' => $userId, 's' => $selector,
        'h' => hash('sha256', $validator), 'l' => 'تست', 'd' => $daysLeft,
    ]);
    $_COOKIE['daftar_device'] = $selector . ':' . $validator;
};

/** نشستی می‌سازد که مهلتش گذشته است. */
$staleSession = function (int $minutes = 60) use ($userId): void {
    $_SESSION = [
        'user_id'         => $userId,
        'full_name'       => 'کاربر تست اعتماد',
        'username'        => '__test_trust',
        'role'            => 'user',
        'session_minutes' => $minutes,
        'login_time'      => time() - 7200,
        'last_seen'       => time() - 7200,   // دو ساعت پیش
    ];
};

$deviceCount = function () use ($pdo, $userId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    return (int)$st->fetchColumn();
};

/** چند ثانیه تا سررسیدِ دستگاه مانده است. */
$secondsLeft = function () use ($pdo, $userId): int {
    $st = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM trusted_devices
         WHERE user_id = :u ORDER BY id DESC LIMIT 1'
    );
    $st->execute(['u' => $userId]);
    return (int)$st->fetchColumn();
};

// ---------------------------------------------------------------
T::group('۱ — انقضای مهلت با دستگاه مورد اعتماد');

$trustDevice(2);
$staleSession(60);

T::same(1, $deviceCount(), 'دستگاه مورد اعتماد پیش از انقضا ثبت است');
$beforeSlide = $secondsLeft();

$stillIn = Auth::isLoggedIn();

T::ok($stillIn, 'با دستگاه مورد اعتماد، پس از انقضای مهلت وارد می‌ماند');
T::same(1, $deviceCount(), 'اعتماد دستگاه پس از انقضای مهلت پاک نمی‌شود');
T::same($userId, (int)($_SESSION['user_id'] ?? 0), 'نشست تازه به همان کاربر تعلق دارد');
T::ok(
    (time() - (int)($_SESSION['last_seen'] ?? 0)) < 60,
    'مهلت از نو شروع می‌شود'
);

// ---------------------------------------------------------------
T::group('۲ — مهلت کشویی است، نه ثابت');

if (!$hasWindow) {
    T::skip('کشویی بودن سررسید', 'ستون session_minutes هنوز نیست');
} else {
    // سررسید پیش از ورود ۲ روز مانده بود؛ کاربر مهلت ۶۰ دقیقه دارد، پس
    // پس از ورودِ خودکار باید سررسید روی ~۶۰ دقیقه از **الان** بنشیند.
    $afterSlide = $secondsLeft();

    T::ok(
        $afterSlide !== $beforeSlide,
        'سررسید دست‌نخورده نمانده است',
        "پیش: {$beforeSlide}s   پس: {$afterSlide}s"
    );
    T::ok(
        abs($afterSlide - 3600) < 120,
        'سررسید دقیقاً روی مهلتِ کاربر (۶۰ دقیقه) از نو تنظیم شد',
        "انتظار ≈3600s، ولی {$afterSlide}s"
    );

    // و بار دوم هم باید دوباره جلو برود — یعنی هر استفاده تمدید می‌کند.
    $deviceId = (int)$_SESSION['trusted_device_id'];
    $pdo->prepare('UPDATE trusted_devices SET expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = :id')
        ->execute(['id' => $deviceId]);
    $shrunk = $secondsLeft();

    $staleSession(60);
    Auth::isLoggedIn();
    $reSlid = $secondsLeft();

    T::ok(
        $reSlid > $shrunk + 60,
        'استفاده‌ی بعدی هم دوباره تمدید می‌کند',
        "پیش: {$shrunk}s   پس: {$reSlid}s"
    );
}

// ---------------------------------------------------------------
T::group('۳ — «بدون مهلت» هرگز بیرون نمی‌اندازد');

if (!$hasWindow) {
    T::skip('گزینه‌ی بدون مهلت', 'ستون session_minutes هنوز نیست');
} else {
    $setWindow(0);
    unset($_COOKIE['daftar_device']);   // بدون پلِ کوکی، تا فقط خودِ مهلت سنجیده شود

    $_SESSION = [
        'user_id'         => $userId,
        'full_name'       => 'کاربر تست اعتماد',
        'username'        => '__test_trust',
        'role'            => 'user',
        'session_minutes' => 0,
        'login_time'      => time() - (400 * 86400),
        'last_seen'       => time() - (400 * 86400),   // بیش از یک سال بی‌فعالیت
    ];

    T::ok(Auth::isLoggedIn(), 'با «بدون مهلت»، حتی پس از یک سال بی‌فعالیتی وارد می‌ماند');
    T::same($userId, (int)($_SESSION['user_id'] ?? 0), 'و نشست همان کاربر است');
}

// ---------------------------------------------------------------
T::group('۴ — مهلت کوتاه واقعاً منقضی می‌کند');

if (!$hasWindow) {
    T::skip('مهلت کوتاه', 'ستون session_minutes هنوز نیست');
} else {
    $setWindow(1);
    unset($_COOKIE['daftar_device']);

    $_SESSION = [
        'user_id'         => $userId,
        'full_name'       => 'کاربر تست اعتماد',
        'username'        => '__test_trust',
        'role'            => 'user',
        'session_minutes' => 1,
        'login_time'      => time() - 300,
        'last_seen'       => time() - 300,   // پنج دقیقه بی‌فعالیت، مهلت یک دقیقه
    ];

    T::ok(!Auth::isLoggedIn(), 'با مهلت یک‌دقیقه‌ای، پنج دقیقه بی‌فعالیتی بیرون می‌اندازد');
    T::ok(empty($_SESSION['user_id']), 'و نشست خالی می‌شود');
}

// ---------------------------------------------------------------
T::group('۵ — خروجِ خواسته‌ی کاربر');

// ⚠ ردیف‌های گروه‌های قبلی پاک می‌شوند تا شمارش بی‌ابهام باشد.
//   نسخه‌ی اول این کار را نمی‌کرد و تست شکست — ولی خرابی از کد نبود:
//   `logout()` عمداً فقط دستگاهِ **خودش** را باطل می‌کند، نه همه‌ی
//   دستگاه‌های کاربر. اگر روزی همه را پاک کند، کاربری که روی گوشی
//   خروج می‌زند، لپ‌تاپش را هم بیرون انداخته.
$pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :u')->execute(['u' => $userId]);

$setWindow(60);
$trustDevice(30);                        // دستگاه «الف» — همینی که خروج می‌زند
$thisCookie = $_COOKIE['daftar_device'];

// دستگاه «ب» — گوشیِ دیگرِ همان کاربر، که نباید دست بخورد
$otherSelector = bin2hex(random_bytes(12));
$pdo->prepare(
    'INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at, last_used_at)
     VALUES (:u, :s, :h, :l, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())'
)->execute([
    'u' => $userId, 's' => $otherSelector,
    'h' => hash('sha256', 'dummy'), 'l' => 'دستگاه دیگر',
]);

$_COOKIE['daftar_device'] = $thisCookie;
$staleSession(60);
Auth::isLoggedIn();                      // تا نشست و شناسه‌ی دستگاه ساخته شود
T::same(2, $deviceCount(), 'پیش از خروج، دو دستگاه ثبت است');

// این باید اعتماد را باطل کند؛ وگرنه «خروج» معنایی ندارد و کسی که
// گوشی را دست می‌گیرد می‌تواند دوباره وارد شود.
Auth::logout();

T::same(1, $deviceCount(), 'زدنِ خروج، اعتماد این دستگاه را باطل می‌کند');
T::ok(empty($_SESSION['user_id']), 'پس از خروج، نشست خالی است');

$st = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u AND selector = :s');
$st->execute(['u' => $userId, 's' => $otherSelector]);
T::same(1, (int)$st->fetchColumn(), 'دستگاهِ دیگرِ همان کاربر دست‌نخورده می‌ماند');

// ---------------------------------------------------------------
T::group('۶ — انقضای مهلت بدون دستگاه مورد اعتماد');

unset($_COOKIE['daftar_device']);
$staleSession(60);

T::ok(!Auth::isLoggedIn(), 'بدون دستگاه مورد اعتماد، انقضای مهلت بیرون می‌اندازد');
T::ok(empty($_SESSION['user_id']), 'و نشست هم خالی می‌شود');

// ---------------------------------------------------------------
T::group('۷ — فهرست گزینه‌ها');

// پروفایل و اندپوینت هر دو از همین فهرست می‌خوانند؛ اگر مقداری اینجا
// نباشد، در پروفایل دیده می‌شود ولی هنگام ذخیره بی‌سروصدا صفر می‌شود.
T::ok(Auth::isValidSessionWindow(0), '«بدون مهلت» مقدار معتبری است');
T::ok(Auth::isValidSessionWindow(1), 'یک دقیقه مقدار معتبری است');
T::ok(Auth::isValidSessionWindow(43200), 'یک ماه مقدار معتبری است');
T::ok(!Auth::isValidSessionWindow(7), 'مقدار خارج از فهرست پذیرفته نمی‌شود');
T::ok(!Auth::isValidSessionWindow(-1), 'مقدار منفی پذیرفته نمی‌شود');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
