<?php
/**
 * تست ثبت و ماندگاری ایمیل کاربر.
 *
 * چرا این تست هست: ایمیل حالا هم راه ورود است و هم تنها راه بازیابی
 * رمز. یک باگ واقعی این بود که فرم ویرایش کاربر در پنل مدیر، اگر فیلد
 * ایمیلش خالی ارسال می‌شد، ایمیل ثبت‌شده را NULL می‌کرد. آن فیلد را
 * جاوااسکریپت پر می‌کند، پس هر بار که اسکریپت اجرا نمی‌شد (نسخه‌ی
 * کش‌شده، خطای اسکریپت) یک ذخیره‌ی ساده ایمیل را بی‌سروصدا پاک می‌کرد
 * و کاربر می‌دید «ایمیل نمی‌مونه» — بدون هیچ پیام خطایی.
 *
 * قاعده‌ی حاصل: ورودی خالی یعنی «دست نزن»، نه «پاک کن».
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

// ---------- نگهبان: فقط خط فرمان ----------
// این فایل داخل ریشه‌ی وب است و بدون این نگهبان، هر کسی می‌توانست با
// باز کردن آدرسش در مرورگر تست را روی دیتابیس واقعی اجرا کند — تست‌ها
// کاربر و رکورد می‌سازند و پاک می‌کنند.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}


require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('ایمیل کاربر');
    T::blocked('تست ایمیل کاربر', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('ایمیل کاربر');
    T::blocked('تست ایمیل کاربر', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!usersHaveEmailColumn($pdo)) {
    T::group('ایمیل کاربر');
    T::skip('تست ایمیل کاربر', 'ستون email نیست — migration را اجرا کنید');
    exit(T::report());
}

// کاربران آزمایشی جدا؛ به داده‌ی واقعی دست نمی‌زنیم
$U1 = '__test_email_a';
$U2 = '__test_email_b';
$cleanup = function () use ($pdo, $U1, $U2) {
    $pdo->prepare('DELETE FROM users WHERE username IN (:a, :b)')->execute(['a' => $U1, 'b' => $U2]);
};
$cleanup();

$mk = function (string $username, ?string $email) use ($pdo): int {
    $pdo->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, role, is_active)
         VALUES (:f, :u, :e, :h, "user", 1)'
    )->execute([
        'f' => 'کاربر آزمایشی', 'u' => $username, 'e' => $email,
        'h' => password_hash('Whatever123', PASSWORD_DEFAULT),
    ]);
    return (int)$pdo->lastInsertId();
};
$emailOf = function (int $id) use ($pdo): ?string {
    $s = $pdo->prepare('SELECT email FROM users WHERE id = :id');
    $s->execute(['id' => $id]);
    return $s->fetchColumn() ?: null;
};

$idA = $mk($U1, 'a@example.invalid');
$idB = $mk($U2, null);

try {
    // ---------------------------------------------------------------
    T::group('ثبت ایمیل');

    T::same('', saveUserEmail($pdo, $idB, 'b@example.invalid'), 'ثبت ایمیل تازه خطا نمی‌دهد');
    T::same('b@example.invalid', $emailOf($idB), 'ایمیل تازه در دیتابیس نشست');

    T::same('', saveUserEmail($pdo, $idA, '  A2@Example.invalid  '), 'فاصله‌های اضافه پذیرفته می‌شوند');
    T::same('A2@Example.invalid', $emailOf($idA), 'فاصله‌ها حذف شدند');

    // ---------------------------------------------------------------
    T::group('ورودی خالی یعنی دست نزن — نه پاک کن');

    // این همان باگی است که کاربر گزارش کرد
    T::same('', saveUserEmail($pdo, $idA, ''), 'ورودی خالی خطا نمی‌دهد');
    T::same('A2@Example.invalid', $emailOf($idA), 'ایمیل بعد از ذخیره‌ی خالی سر جایش ماند');

    T::same('', saveUserEmail($pdo, $idA, '   '), 'ورودی فقط-فاصله هم خالی حساب می‌شود');
    T::same('A2@Example.invalid', $emailOf($idA), 'ایمیل بعد از ورودی فقط-فاصله هم ماند');

    // پاک کردن باید صریح خواسته شود
    $tmp = $emailOf($idA);
    T::same('', saveUserEmail($pdo, $idA, '', true), 'پاک کردن صریح انجام می‌شود');
    T::same(null, $emailOf($idA), 'با allowClear ایمیل NULL شد');
    saveUserEmail($pdo, $idA, $tmp);   // برگرداندن برای تست‌های بعدی

    // ---------------------------------------------------------------
    T::group('اعتبارسنجی');

    foreach (['not-an-email', 'a@', '@b.com', 'a b@c.com'] as $bad) {
        T::ok(saveUserEmail($pdo, $idB, $bad) !== '', "آدرس نامعتبر رد شد: $bad");
    }
    T::same('b@example.invalid', $emailOf($idB), 'ایمیل بعد از تلاش نامعتبر عوض نشد');

    $tooLong = str_repeat('x', 185) . '@example.invalid';
    T::ok(saveUserEmail($pdo, $idB, $tooLong) !== '', 'آدرس بلندتر از ۱۹۰ کاراکتر رد شد');

    // ---------------------------------------------------------------
    T::group('یکتا بودن');

    $err = saveUserEmail($pdo, $idB, 'A2@Example.invalid');
    T::ok($err !== '', 'ایمیل تکراری رد شد');
    T::ok(str_contains($err, $U1), 'پیام خطا می‌گوید ایمیل مال کدام کاربر است');
    T::same('b@example.invalid', $emailOf($idB), 'ایمیل قبلی بعد از تلاش تکراری دست‌نخورده ماند');

    // ثبت دوباره‌ی همان ایمیل روی همان کاربر نباید «تکراری» حساب شود
    T::same('', saveUserEmail($pdo, $idB, 'b@example.invalid'), 'ثبت مجدد ایمیل خودِ کاربر مجاز است');

} finally {
    $cleanup();
}

exit(T::report());
