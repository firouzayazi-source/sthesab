<?php
/**
 * تست بازیابی رمز.
 *
 * چیزی که این تست دنبالش است، فقط «کار می‌کند یا نه» نیست. بازیابی رمز
 * راهی است که عمداً باز گذاشته‌ایم تا کسی بدون رمز وارد شود؛ پس هر
 * قفلی که رویش گذاشته‌ایم باید آزموده شود: یک‌بارمصرف بودن، انقضا،
 * توکن جعلی، افشا نکردن وجود کاربر، و باطل شدن دستگاه‌های مورد اعتماد.
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('بازیابی رمز');
    T::skip('تست بازیابی رمز', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/password_reset.php';

try {
    $pdo = Database::getConnection();
    $pdo->query('SELECT 1 FROM password_resets LIMIT 1');
} catch (Throwable $e) {
    T::group('بازیابی رمز');
    T::skip('تست بازیابی رمز', 'جدول password_resets نیست — migration را اجرا کنید');
    exit(T::report());
}

// ---------------------------------------------------------------
// کاربر آزمایشی جدا می‌سازیم و آخرش پاکش می‌کنیم؛ به داده‌ی واقعی
// دست نمی‌زنیم.
$TESTU = '__test_reset_user';
$TESTE = '__test_reset@example.invalid';
$cleanup = function () use ($pdo, $TESTU) {
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();
$pdo->prepare(
    'INSERT INTO users (full_name, username, email, password_hash, role, is_active)
     VALUES (:f, :u, :e, :h, "user", 1)'
)->execute([
    'f' => 'کاربر آزمایشی', 'u' => $TESTU, 'e' => $TESTE,
    'h' => password_hash('OldPassword123', PASSWORD_DEFAULT),
]);
$uid = (int)$pdo->lastInsertId();

/** ساخت مستقیم توکن، بدون نیاز به ارسال ایمیل */
$makeToken = function (int $userId, string $expires = '+1 hour') use ($pdo): array {
    $sel = bin2hex(random_bytes(12));
    $val = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO password_resets (user_id, selector, validator_hash, expires_at, request_ip)
         VALUES (:u, :s, :h, :e, "127.0.0.1")'
    )->execute([
        'u' => $userId, 's' => $sel, 'h' => hash('sha256', $val),
        'e' => (new DateTimeImmutable($expires))->format('Y-m-d H:i:s'),
    ]);
    return [$sel, $val];
};

// ---------------------------------------------------------------
T::group('توکن سالم');

[$sel, $val] = $makeToken($uid);
$v = PasswordReset::verify($sel, $val);
T::ok($v['ok'], 'توکن تازه معتبر است', $v['reason']);
T::same($TESTU, $v['user']['username'] ?? '', 'کاربر درست را برمی‌گرداند');

// ---------------------------------------------------------------
T::group('توکن‌هایی که باید رد شوند');

[$s2, $v2] = $makeToken($uid);
T::same('mismatch', PasswordReset::verify($s2, str_repeat('a', 64))['reason'],
    'validator اشتباه رد می‌شود');
T::same('not-found', PasswordReset::verify(str_repeat('b', 24), $v2)['reason'],
    'selector ناشناخته رد می‌شود');
T::same('malformed', PasswordReset::verify('', '')['reason'],
    'توکن خالی رد می‌شود');
T::same('malformed', PasswordReset::verify('نه-هگز', $v2)['reason'],
    'توکن غیر هگز رد می‌شود');

[$s3, $v3] = $makeToken($uid, '-5 minutes');
T::same('expired', PasswordReset::verify($s3, $v3)['reason'], 'توکن منقضی رد می‌شود');

// کاربر غیرفعال
$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute(['id' => $uid]);
[$s4, $v4] = $makeToken($uid);
T::same('inactive', PasswordReset::verify($s4, $v4)['reason'], 'کاربر غیرفعال رد می‌شود');
$pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $uid]);

// ---------------------------------------------------------------
T::group('تعیین رمز تازه');

[$s5, $v5] = $makeToken($uid);
T::same('weak', PasswordReset::complete($s5, $v5, 'کوتاه')['reason'], 'رمز کوتاه رد می‌شود');

$res = PasswordReset::complete($s5, $v5, 'BrandNewPass456');
T::ok($res['ok'], 'رمز با توکن سالم عوض می‌شود', $res['reason'] ?? '');

$hash = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$hash->execute(['id' => $uid]);
$h = (string)$hash->fetchColumn();
T::ok(password_verify('BrandNewPass456', $h), 'رمز تازه واقعاً ذخیره شده');
T::ok(!password_verify('OldPassword123', $h), 'رمز قدیمی دیگر کار نمی‌کند');

// ---------------------------------------------------------------
T::group('یک‌بارمصرف بودن');

T::same('used', PasswordReset::verify($s5, $v5)['reason'], 'همان توکن بار دوم رد می‌شود');
T::ok(!PasswordReset::complete($s5, $v5, 'AnotherPass789')['ok'],
    'با توکن مصرف‌شده نمی‌شود دوباره رمز عوض کرد');

// توکن‌های دیگرِ همان کاربر هم باید باطل شده باشند
T::same('used', PasswordReset::verify($s2, $v2)['reason'],
    'توکن‌های قدیمی‌تر همان کاربر هم باطل شده‌اند');

// ---------------------------------------------------------------
T::group('دستگاه‌های مورد اعتماد باطل می‌شوند');

try {
    $pdo->prepare(
        'INSERT INTO trusted_devices (user_id, selector, token_hash, device_label, expires_at)
         VALUES (:u, :s, :h, "تست", DATE_ADD(NOW(), INTERVAL 30 DAY))'
    )->execute(['u' => $uid, 's' => bin2hex(random_bytes(8)), 'h' => str_repeat('c', 64)]);

    [$s6, $v6] = $makeToken($uid);
    PasswordReset::complete($s6, $v6, 'YetAnotherPass321');

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM trusted_devices WHERE user_id = :u');
    $cnt->execute(['u' => $uid]);
    T::same(0, (int)$cnt->fetchColumn(), 'بعد از بازیابی، دستگاه مورد اعتماد نمی‌ماند');
} catch (PDOException $e) {
    T::skip('باطل شدن دستگاه‌های مورد اعتماد', 'ساختار trusted_devices فرق دارد');
}

// ---------------------------------------------------------------
T::group('درخواست بازیابی چیزی درباره‌ی وجود کاربر لو نمی‌دهد');

// نه کاربر موجود و نه ناموجود نباید استثنا بدهند یا رفتار متفاوتی
// نشان دهند. تنها تفاوت مجاز، مقدار error داخلی است که به کاربر
// نمایش داده نمی‌شود.
$r1 = PasswordReset::request('__definitely_not_a_user__', '127.0.0.1', 'https://example.com');
T::ok(!$r1['sent'] && $r1['error'] === 'no-target', 'کاربر ناموجود بی‌سروصدا رد می‌شود');

$pdo->prepare('UPDATE users SET email = NULL WHERE id = :id')->execute(['id' => $uid]);
$r2 = PasswordReset::request($TESTU, '127.0.0.1', 'https://example.com');
T::ok(!$r2['sent'] && $r2['error'] === 'no-target', 'کاربر بدون ایمیل بی‌سروصدا رد می‌شود');
$pdo->prepare('UPDATE users SET email = :e WHERE id = :id')->execute(['e' => $TESTE, 'id' => $uid]);

// ---------------------------------------------------------------
T::group('محدودیت تعداد درخواست');

$pdo->prepare('DELETE FROM password_resets WHERE user_id = :u')->execute(['u' => $uid]);
$blocked = false;
for ($i = 0; $i < PasswordReset::MAX_PER_USER + 2; $i++) {
    $r = PasswordReset::request($TESTU, '10.0.0.99', 'https://example.com');
    if ($r['error'] === 'rate-limited') { $blocked = true; break; }
}
T::ok($blocked, 'بعد از چند درخواست پیاپی، جلوی درخواست تازه گرفته می‌شود');

// ---------------------------------------------------------------
T::group('منطقه‌ی زمانی — توکن نباید زودتر از موعد منقضی شود');

// این تست از یک باگ واقعی می‌آید: expires_at را PHP می‌ساخت و مقایسه
// هم در PHP انجام می‌شد، ولی Auth::initSession منطقه‌ی زمانی PHP را
// عوض می‌کند. نتیجه این بود که لینک بازیابی بلافاصله «منقضی» می‌شد.
// حالا هر دو کار را دیتابیس می‌کند. اینجا همان اختلاف را شبیه‌سازی
// می‌کنیم: توکن با یک منطقه ساخته و با منطقه‌ی دیگری بررسی شود.
$tzBefore = date_default_timezone_get();

date_default_timezone_set('UTC');
$rq = PasswordReset::request($TESTU, '10.1.2.3', 'https://example.com');
$fresh = $pdo->prepare('SELECT selector FROM password_resets WHERE user_id = :u
                        ORDER BY id DESC LIMIT 1');
$fresh->execute(['u' => $uid]);
$selTz = (string)$fresh->fetchColumn();

date_default_timezone_set('Asia/Tehran');   // همان کاری که initSession می‌کند
$vTz = PasswordReset::verify($selTz, str_repeat('0', 64));
T::same('mismatch', $vTz['reason'],
    'توکن تازه با منطقه‌ی زمانی متفاوت، «منقضی» اعلام نمی‌شود');

date_default_timezone_set('Pacific/Kiritimati');  // UTC+14، بدترین حالت
$vTz2 = PasswordReset::verify($selTz, str_repeat('0', 64));
T::same('mismatch', $vTz2['reason'],
    'حتی با اختلاف ۱۴ ساعته هم انقضای زودهنگام رخ نمی‌دهد');

date_default_timezone_set($tzBefore);

// ---------------------------------------------------------------
T::group('آدرس پایه در برابر دستکاری سرآیند Host');

$_SERVER['HTTP_HOST'] = 'evil.example.net';
if (defined('APP_URL') && APP_URL !== '') {
    T::ok(!str_contains(appBaseUrl(), 'evil'),
        'با APP_URL تنظیم‌شده، Host مهاجم نادیده گرفته می‌شود', appBaseUrl());
} else {
    T::skip('محافظت در برابر Host جعلی', 'APP_URL در config تنظیم نشده');
}

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
