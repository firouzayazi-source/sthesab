<?php
/**
 * تست «این دستگاه را ۳۰ روز به خاطر بسپار» در برابر انقضای مهلت.
 *
 * چرا این تست هست: یک بار این قابلیت عملاً کار نمی‌کرد و هیچ خطایی هم
 * نمی‌داد. `isLoggedIn()` هنگام انقضای مهلت `logout()` را صدا می‌زد، و
 * `logout()` — که برای خروجِ خواسته‌ی کاربر نوشته شده — ردیف
 * `trusted_devices` را پاک می‌کرد. یعنی همان مهلت یک‌ساعته‌ای که قرار
 * بود این قابلیت از رویش پل بزند، خودِ آن را نابود می‌کرد و کاربر با
 * وجود روشن بودنِ گزینه، هر ساعت دوباره رمز می‌خواست.
 *
 * سه چیز با هم باید درست بمانند، وگرنه یا قابلیت بی‌فایده است یا امنیت
 * کم می‌شود:
 *   ۱. انقضای مهلت + دستگاه مورد اعتماد  → وارد بماند، اعتماد سالم
 *   ۲. خروجِ خواسته‌ی کاربر               → اعتماد باطل شود
 *   ۳. انقضای مهلت بدون دستگاه مورد اعتماد → بیرون بیفتد
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
    T::skip('تست اعتماد دستگاه', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('اعتماد دستگاه');
    T::skip('تست اعتماد دستگاه', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('trusted_devices')) {
    T::group('اعتماد دستگاه');
    T::skip('تست اعتماد دستگاه', 'جدول trusted_devices وجود ندارد');
    exit(T::report());
}

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
    "INSERT INTO users (username, password_hash, full_name, role, is_active, session_hours)
     VALUES (:u, :p, 'کاربر تست اعتماد', 'user', 1, 1)"
)->execute(['u' => $USER, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
ob_start();   // تا setcookie و session_regenerate_id هشدار «هدر ارسال شد» ندهند

/** یک دستگاه مورد اعتماد می‌سازد و کوکی‌اش را در $_COOKIE می‌گذارد. */
$trustDevice = function () use ($pdo, $userId): void {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at, last_used_at)
         VALUES (:u, :s, :h, :l, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())'
    )->execute([
        'u' => $userId, 's' => $selector,
        'h' => hash('sha256', $validator), 'l' => 'تست',
    ]);
    $_COOKIE['daftar_device'] = $selector . ':' . $validator;
};

/** نشستی می‌سازد که مهلتش گذشته است. */
$staleSession = function () use ($userId): void {
    $_SESSION = [
        'user_id'       => $userId,
        'full_name'     => 'کاربر تست اعتماد',
        'username'      => '__test_trust',
        'role'          => 'user',
        'session_hours' => 1,
        'login_time'    => time() - 7200,
        'last_seen'     => time() - 7200,   // دو ساعت پیش، مهلت یک ساعت
    ];
};

$deviceCount = function () use ($pdo, $userId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    return (int)$st->fetchColumn();
};

// ---------------------------------------------------------------
T::group('۱ — انقضای مهلت با دستگاه مورد اعتماد');

$trustDevice();
$staleSession();

T::same(1, $deviceCount(), 'دستگاه مورد اعتماد پیش از انقضا ثبت است');

$stillIn = Auth::isLoggedIn();

T::ok($stillIn, 'با دستگاه مورد اعتماد، پس از انقضای مهلت وارد می‌ماند');
T::same(1, $deviceCount(), 'اعتماد دستگاه پس از انقضای مهلت پاک نمی‌شود');
T::same($userId, (int)($_SESSION['user_id'] ?? 0), 'نشست تازه به همان کاربر تعلق دارد');
T::ok(
    (time() - (int)($_SESSION['last_seen'] ?? 0)) < 60,
    'مهلت از نو شروع می‌شود'
);

// ---------------------------------------------------------------
T::group('۲ — خروجِ خواسته‌ی کاربر');

// این باید اعتماد را باطل کند؛ وگرنه «خروج» معنایی ندارد و کسی که
// گوشی را دست می‌گیرد می‌تواند دوباره وارد شود.
Auth::logout();

T::same(0, $deviceCount(), 'زدنِ خروج، اعتماد این دستگاه را باطل می‌کند');
T::ok(empty($_SESSION['user_id']), 'پس از خروج، نشست خالی است');

// ---------------------------------------------------------------
T::group('۳ — انقضای مهلت بدون دستگاه مورد اعتماد');

unset($_COOKIE['daftar_device']);
$staleSession();

T::ok(!Auth::isLoggedIn(), 'بدون دستگاه مورد اعتماد، انقضای مهلت بیرون می‌اندازد');
T::ok(empty($_SESSION['user_id']), 'و نشست هم خالی می‌شود');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
