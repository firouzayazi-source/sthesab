<?php
/**
 * ثبت‌نامِ خودسرویس — پشتِ کلیدِ مدیر.
 *
 * ⛔ **پیش‌فرض خاموش، و این عمدی است.** هر نصبی که تا امروز بالا آمده،
 *    یک دفترِ خصوصی است که مدیرش خودش کاربرها را می‌سازد. روشن شدنِ
 *    خودکارِ ثبت‌نام با یک `git pull`، در ساکت‌ترین حالتِ ممکن، آن دفتر
 *    را به روی اینترنت باز می‌کرد. کسی که می‌خواهد اپ را بفروشد،
 *    خودش روشنش می‌کند.
 *
 * ⛔ منطقِ ساختِ کاربر **یک جا**ست (`createUserAccount()`) و هم
 *    ثبت‌نامِ خودسرویس از آن رد می‌شود هم پنل مدیر. اگر دو نسخه
 *    می‌شدند، قاعده‌ای مثل «کاربر تازه باید کیف پول داشته باشد» در
 *    یکی می‌ماند و از دیگری می‌افتاد — و آن‌وقت تراکنشِ اولِ آن کاربر
 *    در هیچ حسابی نمی‌نشست.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/login_throttle.php';

/** کلیدِ تنظیم در `app_settings`. */
const SIGNUP_SETTING = 'allow_signup';

/** حداکثر ثبت‌نام از یک IP در یک ساعت. */
const SIGNUP_MAX_PER_IP = 5;

/** آیا ثبت‌نامِ خودسرویس روشن است؟ */
function signupEnabled(): bool
{
    return getSetting(SIGNUP_SETTING, '0') === '1';
}

/**
 * ⛔ همه‌ی قواعدِ اعتبارسنجیِ یک حسابِ تازه، در یک جا.
 *
 * @return string '' یعنی معتبر، وگرنه متنِ خطا.
 */
function validateNewUser(PDO $pdo, string $fullName, string $username,
                         string $email, string $password, string $confirm): string
{
    if ($fullName === '' || $username === '' || $password === '') {
        return 'تمام فیلدهای الزامی را پر کنید.';
    }
    if (mb_strlen($fullName) > 100) {
        return 'نام و نام خانوادگی نباید بیشتر از ۱۰۰ کاراکتر باشد.';
    }
    if (mb_strlen($username) > 50) {
        return 'نام کاربری نباید بیشتر از ۵۰ کاراکتر باشد.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        return 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد.';
    }
    // ⚠ ایمیل اینجا **الزامی** است، برخلافِ ویرایشِ کاربر در پنل مدیر.
    //   کاربری که خودش ثبت‌نام می‌کند هیچ مدیری ندارد که رمزش را برایش
    //   عوض کند؛ بدون ایمیل، اولین فراموشیِ رمز یعنی از دست رفتنِ حساب.
    if ($email === '') {
        return 'ایمیل الزامی است — بدون آن نمی‌توانید رمزتان را بازیابی کنید.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        return 'ایمیل معتبر نیست.';
    }
    if (mb_strlen($password) < 8) {
        return 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
    }
    if ($password !== $confirm) {
        return 'رمز عبور و تکرار آن یکسان نیستند.';
    }

    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    if ($st->fetch()) { return 'این نام کاربری قبلاً استفاده شده است.'; }

    return '';
}

/**
 * ساختِ حسابِ تازه — تنها مسیرِ ساختِ کاربر.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function createUserAccount(PDO $pdo, string $fullName, string $username,
                           string $email, string $password, string $role = 'user'): array
{
    if (!in_array($role, ['admin', 'user'], true)) { $role = 'user'; }

    try {
        $st = $pdo->prepare(
            'INSERT INTO users (full_name, username, password_hash, role, is_active)
             VALUES (:f, :u, :p, :r, 1)'
        );
        $st->execute([
            'f' => $fullName,
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
        ]);
        $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('createUserAccount: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'خطایی در ساخت حساب رخ داد.'];
    }

    // ⚠ ایمیل از `saveUserEmail()` رد می‌شود، نه از کوئریِ بالا: همه‌ی
    //   قواعدِ ایمیل (اعتبار، یکتایی، «خالی یعنی دست نزن») آنجاست.
    $err = saveUserEmail($pdo, $id, $email);
    if ($err !== '') {
        // حساب ساخته شده ولی ایمیل ننشسته — صریح بگو، نه اینکه در سکوت
        // بگذری. کاربر بدون ایمیل نمی‌تواند رمزش را بازیابی کند.
        return ['ok' => true, 'id' => $id, 'error' => $err];
    }

    // بدون این، اولین تراکنشِ کاربر در هیچ حسابی نمی‌نشیند.
    ensureDefaultWallet($id);

    return ['ok' => true, 'id' => $id];
}

/**
 * سدِ ثبت‌نامِ انبوه.
 *
 * ⛔ بدونش، یک صفحه‌ی ثبت‌نامِ باز یعنی هر کسی می‌تواند در چند دقیقه
 *    هزاران حساب بسازد و دیتابیس را پر کند. از همان جدولِ
 *    `login_attempts` استفاده می‌کند تا جدولِ تازه‌ای لازم نشود؛ کلیدِ
 *    نامِ کاربری‌اش ثابت است، پس با شمارشِ ورودِ ناموفق قاطی نمی‌شود.
 *
 * @return int تعداد دقیقه‌ی باقی‌مانده، یا ۰ اگر آزاد است
 */
function signupThrottleMinutes(string $ip): int
{
    if (!LoginThrottle::available()) { return 0; }

    try {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username_tried = :k AND request_ip = :ip
               AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)'
        );
        $st->execute(['k' => '__signup__', 'ip' => $ip]);
        $n = (int)$st->fetchColumn();
        if ($n < SIGNUP_MAX_PER_IP) { return 0; }

        $st = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(MIN(created_at), INTERVAL 60 MINUTE))
             FROM login_attempts
             WHERE username_tried = :k AND request_ip = :ip
               AND created_at > DATE_SUB(NOW(), INTERVAL 60 MINUTE)'
        );
        $st->execute(['k' => '__signup__', 'ip' => $ip]);
        return max(1, (int)$st->fetchColumn());
    } catch (PDOException $e) {
        // جدول نیست — سد بی‌سروصدا خاموش می‌ماند، نه اینکه صفحه بخوابد
        return 0;
    }
}

/** ثبتِ یک ثبت‌نامِ انجام‌شده برای شمارشِ سد. */
function signupThrottleRecord(string $ip): void
{
    if (!LoginThrottle::available()) { return; }
    try {
        Database::getConnection()
            ->prepare('INSERT INTO login_attempts (username_tried, request_ip) VALUES (:k, :ip)')
            ->execute(['k' => '__signup__', 'ip' => $ip]);
    } catch (PDOException $e) { /* مهم نیست */ }
}
