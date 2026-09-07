<?php
/**
 * تست «تصمیمِ مدیر باید به نشستِ زنده برسد».
 *
 * چرا این تست هست: نشستِ PHP فقط یک فایل روی دیسک است و به هیچ چیزی در
 * دیتابیس بند نیست. تا پیش از این، `Auth::isLoggedIn()` فقط به `$_SESSION`
 * اعتماد می‌کرد — پس:
 *
 *   • مدیر کاربری را «غیرفعال» می‌کرد، پیامِ سبز می‌گرفت، و آن کاربر با
 *     مرورگرِ بازش تا هر وقت خودش خارج نمی‌شد (با «بدون مهلت» یعنی
 *     هرگز) کار می‌کرد.
 *   • نقشِ مدیر از کسی گرفته می‌شد و او تا پایانِ نشستش مدیر می‌ماند.
 *   • تغییرِ رمز و «خروج از همه‌ی دستگاه‌ها» فقط کوکیِ دستگاه و توکنِ API را
 *     می‌بردند؛ مرورگری که همان لحظه وارد بود می‌ماند.
 *
 * هیچ‌کدام خطایی نمی‌دادند. فقط پیامِ پنلِ مدیر دروغ بود.
 *
 * چیزهایی که با هم باید درست بمانند:
 *   ۱. کاربرِ فعال با نشستِ کهنه → وارد بماند، نقشش دست‌نخورده
 *   ۲. غیرفعال شدن → نشست خالی شود
 *   ۳. سنجش حداکثر یک بار در دقیقه است، نه هر درخواست (سقفِ هزینه)
 *   ۴. برداشتنِ نقشِ مدیر → `isAdmin()` نادرست، نشست هم درست شود
 *   ۵. دادنِ نقشِ مدیر → همان، برعکس
 *   ۶. مهرِ `access_revoked_at` → نشستِ قدیمی‌تر خالی، نشستِ تازه‌تر بماند
 *   ۷. `revokeAllAccessFor()` واقعاً مهر را می‌نویسد و `renewCurrentSession()`
 *      نشستِ خودِ کاربر را از زیرش بیرون می‌کشد
 *   ۸. حذفِ ردیفِ کاربر → نشست خالی شود
 *   ۹. پنلِ مدیر: غیرفعال‌سازی و «خروج از دستگاه‌ها» هر دو از
 *      `revokeAllAccessFor()` رد می‌شوند (شکل، نه رفتار — رفتارش بالاتر است)
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
    T::group('نشست و تصمیم مدیر');
    T::skip('تست نشست', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('نشست و تصمیم مدیر');
    T::skip('تست نشست', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

$hasRevoke = tableHasColumn('users', 'access_revoked_at');

// ---------------------------------------------------------------
$USER = '__test_adm_sess';

$cleanup = function () use ($pdo, $USER) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $USER]);
    if ($id = $st->fetchColumn()) {
        try { $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :u')->execute(['u' => $id]); } catch (PDOException $e) {}
        try { $pdo->prepare('DELETE FROM api_tokens WHERE user_id = :u')->execute(['u' => $id]); } catch (PDOException $e) {}
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $USER]);
};
$cleanup();

$createUser = function () use ($pdo, $USER): int {
    $pdo->prepare(
        "INSERT INTO users (username, password_hash, full_name, role, is_active)
         VALUES (:u, :p, 'کاربر تست نشست', 'user', 1)"
    )->execute(['u' => $USER, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};
$userId = $createUser();

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
ob_start();   // تا session_regenerate_id هشدار «هدر ارسال شد» ندهد

/**
 * نشستی می‌سازد که آخرین سنجشش **دو دقیقه پیش** بوده، پس `isLoggedIn()`
 * حق دارد دوباره از دیتابیس بپرسد. مهلتِ کاربر «بدون مهلت» (۰) است تا
 * انقضای عادی قاطیِ این تست نشود — آن یکی در test_session_trust است.
 *
 * @param int $loginAgo چند ثانیه پیش وارد شده (برای سنجشِ مهرِ ابطال)
 */
$session = function (string $role = 'user', int $lastSeenAgo = 120, int $loginAgo = 3600) use ($userId): void {
    $_SESSION = [
        'user_id'         => $userId,
        'full_name'       => 'کاربر تست نشست',
        'username'        => '__test_adm_sess',
        'role'            => $role,
        'session_minutes' => 0,
        'login_time'      => time() - $loginAgo,
        'last_seen'       => time() - $lastSeenAgo,
    ];
};

$setActive = function (int $v) use ($pdo, $userId): void {
    $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :id')->execute(['a' => $v, 'id' => $userId]);
};
$setRole = function (string $r) use ($pdo, $userId): void {
    $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute(['r' => $r, 'id' => $userId]);
};

// ===============================================================
T::group('۱. کاربرِ فعال با نشستِ کهنه — وارد می‌ماند');

$session('user');
T::ok(Auth::isLoggedIn(), 'کاربرِ فعال وارد می‌ماند');
T::same($userId, Auth::userId(), 'شناسه‌ی نشست دست‌نخورده است');
T::same('user', Auth::role(), 'نقش دست‌نخورده است');
T::ok(time() - (int)$_SESSION['last_seen'] <= 2, 'last_seen تازه شد (سنجش انجام شد)');

// ===============================================================
T::group('۲. غیرفعال شدن — نشستِ زنده خالی می‌شود');

$setActive(0);
$session('user');
T::ok(!Auth::isLoggedIn(), 'کاربرِ غیرفعال با نشستِ کهنه بیرون می‌افتد');
T::ok(empty($_SESSION['user_id']), 'نشست خالی شده است');
T::ok(!Auth::isAdmin(), 'isAdmin هم نادرست است');
$setActive(1);

// ===============================================================
T::group('۳. سقفِ هزینه — حداکثر یک سنجش در دقیقه');

// ⚠ این گروه یک **مصالحه‌ی عمدی** را ثبت می‌کند، نه یک باگ: با نشستِ
//   تازه (زیرِ یک دقیقه) هیچ کوئری‌ای زده نمی‌شود، پس غیرفعال شدن تا
//   شصت ثانیه دیر می‌رسد. اگر روزی کسی این را «درست» کند و در هر
//   درخواست بپرسد، اینجا قرمز می‌شود و باید آگاهانه تصمیم بگیرد.
$setActive(0);
$session('user', 5);
T::ok(Auth::isLoggedIn(), 'با نشستِ ۵ ثانیه‌ای هنوز سنجشی نمی‌شود (سقفِ یک دقیقه)');
$setActive(1);

// ===============================================================
T::group('۴. برداشتنِ نقشِ مدیر — isAdmin تا یک دقیقه‌ی بعد نادرست است');

$setRole('user');                 // در دیتابیس کاربرِ عادی است
$session('admin');                // ولی نشست هنوز فکر می‌کند مدیر است
T::ok(Auth::isLoggedIn(), 'کاربر وارد می‌ماند (فقط نقش عوض شده)');
T::same('user', $_SESSION['role'] ?? null, 'نقشِ نشست از دیتابیس درست شد');
T::ok(!Auth::isAdmin(), 'isAdmin() نادرست است');

// ===============================================================
T::group('۵. دادنِ نقشِ مدیر — بدونِ خروج و ورودِ دوباره');

$setRole('admin');
$session('user');
T::ok(Auth::isLoggedIn(), 'کاربر وارد می‌ماند');
T::same('admin', $_SESSION['role'] ?? null, 'نقشِ نشست به مدیر رسید');
T::ok(Auth::isAdmin(), 'isAdmin() درست است');
$setRole('user');

// ===============================================================
T::group('۶. مهرِ ابطال — نشستِ قدیمی‌تر می‌رود، تازه‌تر می‌ماند');

if (!$hasRevoke) {
    T::skip('مهرِ ابطال', 'ستون users.access_revoked_at نیست (migration_access_revoke اجرا نشده)');
} else {
    // مهر ده ثانیه پیش — عمداً در دیتابیس ساخته می‌شود، نه با date() در PHP
    $pdo->prepare('UPDATE users SET access_revoked_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE id = :id')
        ->execute(['id' => $userId]);

    $session('user', 120, 3600);   // یک ساعت پیش وارد شده → قدیمی‌تر از مهر
    T::ok(!Auth::isLoggedIn(), 'نشستِ ساخته‌شده پیش از مهر بیرون می‌افتد');
    T::ok(empty($_SESSION['user_id']), 'و خالی شده است');

    $session('user', 120, 0);      // همین حالا وارد شده → تازه‌تر از مهر
    T::ok(Auth::isLoggedIn(), 'نشستِ ساخته‌شده بعد از مهر می‌ماند');

    // NULL یعنی هرگز باطل نشده — نصبِ موجود بعد از migration کسی را بیرون نمی‌اندازد
    $pdo->prepare('UPDATE users SET access_revoked_at = NULL WHERE id = :id')->execute(['id' => $userId]);
    $session('user', 120, 86400 * 400);   // نشستِ خیلی قدیمی
    T::ok(Auth::isLoggedIn(), 'بدونِ مهر (NULL) حتی نشستِ خیلی قدیمی هم می‌ماند');
}

// ===============================================================
T::group('۷. revokeAllAccessFor() مهر می‌زند؛ renewCurrentSession() خودِ کاربر را نجات می‌دهد');

if (!$hasRevoke) {
    T::skip('مهرِ revokeAllAccessFor', 'ستون users.access_revoked_at نیست');
} else {
    // یک دستگاهِ مورد اعتماد هم بگذاریم تا معلوم شود بقیه‌ی ابطال هنوز کار می‌کند
    $pdo->prepare(
        'INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at, last_used_at)
         VALUES (:u, :s, :h, :l, DATE_ADD(NOW(), INTERVAL 2 DAY), NOW())'
    )->execute(['u' => $userId, 's' => bin2hex(random_bytes(12)), 'h' => hash('sha256', 'x'), 'l' => 'تست']);

    // نشستِ خودِ کاربر — همان که تغییرِ رمز را زده — یک ساعت پیش ساخته شده
    $session('user', 120, 3600);
    revokeAllAccessFor($userId);

    $st = $pdo->prepare('SELECT access_revoked_at IS NOT NULL AND access_revoked_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND) FROM users WHERE id = :id');
    $st->execute(['id' => $userId]);
    T::ok((bool)$st->fetchColumn(), 'access_revoked_at همین حالا نوشته شد');

    $st = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    T::same(0, (int)$st->fetchColumn(), 'دستگاه‌های مورد اعتماد هم پاک شدند (رفتارِ قبلی حفظ شده)');

    // بدونِ renew: خودِ کاربر هم بیرون می‌افتاد
    $probe = $_SESSION;
    T::ok(!Auth::isLoggedIn(), 'بدونِ renew، نشستِ خودِ کاربر هم قدیمی‌تر از مهر است و می‌رود');

    // با renew: می‌ماند
    $_SESSION = $probe;
    Auth::renewCurrentSession();
    $_SESSION['last_seen'] = time() - 120;   // تا سنجش واقعاً انجام شود، نه از سقفِ یک‌دقیقه رد شود
    T::ok(Auth::isLoggedIn(), 'با renewCurrentSession() همان کاربر وارد می‌ماند');

    // و نشستِ دیگری از همان کاربر (مرورگرِ دوم) که پیش از مهر وارد شده بود، می‌رود
    $session('user', 120, 3600);
    T::ok(!Auth::isLoggedIn(), 'مرورگرِ دومِ همان کاربر بیرون می‌افتد');

    $pdo->prepare('UPDATE users SET access_revoked_at = NULL WHERE id = :id')->execute(['id' => $userId]);
}

// ===============================================================
T::group('۸. حذفِ کاربر — نشست خالی می‌شود');

$session('user');
$pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
T::ok(!Auth::isLoggedIn(), 'کاربری که ردیفش پاک شده بیرون می‌افتد');
T::ok(empty($_SESSION['user_id']), 'نشست خالی شده است');

// ===============================================================
T::group('۹. پنلِ مدیر — هر دو مسیر از revokeAllAccessFor() رد می‌شوند');

$src = file_get_contents(__DIR__ . '/../admin/users.php');

/** بدنه‌ی یک شاخه‌ی action تا شاخه‌ی بعدی. */
$branch = function (string $action) use ($src): string {
    $start = strpos($src, "\$action === '{$action}'");
    if ($start === false) { return ''; }
    $end = strpos($src, 'elseif ($action ===', $start + 10);
    if ($end === false) { $end = strpos($src, "\n}\n", $start); }
    return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
};

$toggle = $branch('toggle_status');
T::ok($toggle !== '', 'شاخه‌ی toggle_status هست');
T::ok(str_contains($toggle, 'revokeAllAccessFor('), 'غیرفعال‌سازی revokeAllAccessFor() را صدا می‌زند');

$revoke = $branch('revoke_access');
T::ok($revoke !== '', 'شاخه‌ی revoke_access هست');
T::ok(str_contains($revoke, 'revokeAllAccessFor('), '«خروج از دستگاه‌ها» revokeAllAccessFor() را صدا می‌زند');
T::ok(str_contains($revoke, '$currentUserId'), 'مدیر روی حسابِ خودش نمی‌زند (راهش پروفایل است)');
T::ok(str_contains($src, 'name="action" value="revoke_access"'), 'دکمه‌اش در جدول رندر می‌شود');

// ---------------------------------------------------------------
$cleanup();
ob_end_flush();
exit(T::report());
