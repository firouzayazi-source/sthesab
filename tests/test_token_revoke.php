<?php
/**
 * تستِ «رمز که عوض شد، هر دسترسیِ قبلی باطل می‌شود».
 *
 * ⛔ چرا: عوض کردنِ رمز به‌تنهایی مهاجم را بیرون نمی‌کند. توکنِ
 *    `api/v1` نه به رمز وابسته است نه به نشست — ۹۰ روز زنده می‌ماند و
 *    رمزِ تازه هیچ اثری رویش ندارد. یعنی کاربری که حسابش لو رفته رمز را
 *    عوض می‌کند، پیام «رمز عوض شد» می‌گیرد، خیالش راحت می‌شود، و مهاجم
 *    همچنان با اپ موبایل داخل است. **هیچ خطایی، هیچ نشانه‌ای.**
 *
 * قاعده ۱۴ در `test_api_contract.php` فقط *شکلِ* کد را می‌سنجد (که هر
 * مسیرِ نوشتنِ رمز تابع را صدا بزند). این تست *رفتار* را می‌سنجد: یک
 * توکنِ واقعی می‌سازد، ثابت می‌کند کار می‌کند، رمز را عوض می‌کند، و
 * ثابت می‌کند دیگر کار نمی‌کند.
 *
 * ⚠ سنجشِ توکن در یک **پروسه‌ی جدا** انجام می‌شود، چون `ApiAuth::user()`
 *   نتیجه را در یک ویژگیِ ایستا کش می‌کند؛ در همان پروسه، سنجشِ دوم
 *   جوابِ کهنه می‌داد و تست بی‌آنکه معلوم شود سبز می‌ماند.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('باطل شدنِ دسترسی با تغییر رمز');
    T::skip('تست توکن', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_auth.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('باطل شدنِ دسترسی با تغییر رمز');
    T::skip('تست توکن', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

T::group('باطل شدنِ دسترسی با تغییر رمز');

if (!tableExists('api_tokens')) {
    T::skip('تست توکن', 'migration_api_tokens اجرا نشده');
    exit(T::report());
}

$TESTU = '__test_revoke_user';
$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['api_tokens', 'trusted_devices', 'wallets'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدول شاید نباشد */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();
register_shutdown_function($cleanup);

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر تست باطل‌سازی', 'user', 1)"
)->execute(['u' => $TESTU, 'p' => password_hash('OldPass123!', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();

// ---------- توکن ساخته می‌شود ----------
$token = ApiAuth::issue($userId, 'گوشی تست', 'android');
T::ok(str_contains($token, '.'), 'توکن ساخته شد', 'شکل selector.validator');

/**
 * توکن را در یک پروسه‌ی تازه می‌سنجد — بدونِ کشِ ایستا.
 * خروجی: شناسه‌ی کاربر، یا 'null'.
 */
$probe = function (string $tok) {
    // ⚠ functions.php هم لازم است: ApiAuth::available() از tableExists()
    //   استفاده می‌کند و بدون آن پروسه‌ی سنجش با خطای کشنده می‌مرد — و
    //   چون خروجی‌اش با «null» یکی نبود، تست به‌درستی شکست خورد.
    $code = 'require "' . addslashes(__DIR__) . '/../includes/functions.php";'
          . 'require "' . addslashes(__DIR__) . '/../includes/api_auth.php";'
          . '$_SERVER["HTTP_AUTHORIZATION"] = "Bearer " . $argv[1];'
          . 'echo ApiAuth::userId() ?? "null";';
    return trim((string)shell_exec(
        'php -r ' . escapeshellarg($code) . ' ' . escapeshellarg($tok) . ' 2>&1'
    ));
};

T::same((string)$userId, $probe($token), 'توکنِ تازه واقعاً کار می‌کند');

// ---------- دستگاهِ مورد اعتماد هم بسازیم ----------
$hasTrusted = tableExists('trusted_devices');
if ($hasTrusted) {
    try {
        $pdo->prepare(
            'INSERT INTO trusted_devices (user_id, selector, token_hash, expires_at)
             VALUES (:u, :s, :h, DATE_ADD(NOW(), INTERVAL 30 DAY))'
        )->execute(['u' => $userId, 's' => bin2hex(random_bytes(8)), 'h' => hash('sha256', 'x')]);
    } catch (PDOException $e) { $hasTrusted = false; }
}

$countTokens = function () use ($pdo, $userId) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = :u AND revoked_at IS NULL');
    $st->execute(['u' => $userId]);
    return (int)$st->fetchColumn();
};
T::same(1, $countTokens(), 'یک توکنِ زنده وجود دارد');

// ---------- تغییر رمز ----------
// همان کاری که api/change_password.php می‌کند.
$pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
    ->execute(['h' => password_hash('NewPass456!', PASSWORD_DEFAULT), 'id' => $userId]);
revokeAllAccessFor($userId);

// ---------- بعد از تغییر رمز ----------
T::same(0, $countTokens(), 'هیچ توکنِ زنده‌ای نمانده');
T::same('null', $probe($token),
    '⛔ توکنِ قبلی دیگر کار نمی‌کند — همان چیزی که کلِ این کار برایش است');

if ($hasTrusted) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    T::same(0, (int)$st->fetchColumn(), 'دستگاه‌های مورد اعتماد هم باطل شدند');
} else {
    T::skip('دستگاه مورد اعتماد', 'جدول trusted_devices در دسترس نیست');
}

// ---------- توکنِ تازه بعد از تغییر رمز باید کار کند ----------
// باطل‌سازی نباید حساب را برای همیشه از API محروم کند.
$fresh = ApiAuth::issue($userId, 'گوشی تست ۲', 'android');
T::same((string)$userId, $probe($fresh), 'ورودِ دوباره با رمز تازه، توکنِ سالم می‌دهد');

// ---------- باطل‌سازی به کاربرِ دیگری سرایت نکند ----------
// ⚠ اگر شرطِ user_id از کوئریِ باطل‌سازی بیفتد، تغییر رمزِ یک نفر همه‌ی
//   کاربران را از اپ بیرون می‌اندازد — و چون هیچ خطایی نمی‌دهد، فقط از
//   شکایتِ کاربران فهمیده می‌شود.
$OTHER = '__test_revoke_other';
$pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $OTHER]);
$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role, is_active)
     VALUES (:u, :p, 'کاربر دیگر', 'user', 1)"
)->execute(['u' => $OTHER, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$otherId = (int)$pdo->lastInsertId();
$otherToken = ApiAuth::issue($otherId, 'گوشی دیگری', 'ios');

revokeAllAccessFor($userId);   // فقط کاربرِ اول

T::same((string)$otherId, $probe($otherToken),
    'توکنِ کاربرِ دیگر دست‌نخورده ماند');

$pdo->prepare('DELETE FROM api_tokens WHERE user_id = :u')->execute(['u' => $otherId]);
$pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $otherId]);

exit(T::report());
