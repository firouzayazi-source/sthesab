<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId  = Auth::userId();
$current = postParam('current_password');
$new     = postParam('new_password');
$confirm = postParam('new_password_confirm');

// ⛔ حسابی که با شماره موبایل ساخته شده هنوز **هیچ رمزی ندارد**
//    (`password_hash IS NULL`). خواستنِ «رمز فعلی» از او یعنی هرگز
//    نتواند رمز بگذارد — یعنی همان تنظیمی که برای امن کردنِ حسابش
//    ساخته شده، برای او کار نمی‌کند.
// ⚠ و این هیچ دری باز نمی‌کند: کاربر همین حالا هم واردِ حسابِ خودش
//   است. تنها چیزی که عوض می‌شود این است که «رمزِ قبلی» ای که وجود
//   ندارد، پرسیده نمی‌شود.
$hasPassword = userHasPassword($userId);

$errors = [];
if ($hasPassword && $current === '') { $errors[] = 'رمز فعلی را وارد کنید.'; }
if (mb_strlen($new) < 6) { $errors[] = 'رمز جدید باید حداقل ۶ کاراکتر باشد.'; }
if ($new !== $confirm) { $errors[] = 'رمز جدید و تکرار آن یکسان نیستند.'; }
if ($hasPassword && $new === $current && $new !== '') { $errors[] = 'رمز جدید نباید با رمز فعلی یکی باشد.'; }

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$row = $stmt->fetch();

// ⚠ شاخه‌ی بی‌رمز اصلاً به `password_verify()` نمی‌رسد. (آن تابع با
//   `null` امروز فقط `Deprecated` می‌دهد و `false` برمی‌گرداند، نه خطای
//   کشنده — توضیحِ کاملش در `Auth::verifyCredentials()`.)
if (!$row || ($hasPassword && !password_verify($current, (string)$row['password_hash']))) {
    jsonResponse(['success' => false, 'message' => 'رمز فعلی اشتباه است.'], 403);
}

try {
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $upd = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
    $upd->execute(['h' => $hash, 'id' => $userId]);

    // با تغییر رمز، هم دستگاه‌های مورد اعتماد باطل می‌شوند هم توکن‌های
    // اپ موبایل. توکنِ API به رمز وابسته نیست و ۹۰ روز زنده می‌ماند،
    // پس بدون این، رمزِ تازه مهاجمی را که توکن دارد بیرون نمی‌کرد.
    revokeAllAccessFor($userId);
    // ⛔ مهرِ ابطال همین حالا نوشته شد و نشستِ **خودِ** این کاربر قدیمی‌تر
    //    از آن است؛ بدونِ این خط تا یک دقیقه‌ی بعد بیرون می‌افتاد.
    Auth::renewCurrentSession();

    jsonResponse(['success' => true, 'message' => $hasPassword
        ? 'رمز عبور تغییر کرد. دستگاه‌های مورد اعتماد، اپ‌های متصل و نشست‌های دیگر هم باطل شدند.'
        : 'رمز عبور برای حساب شما تنظیم شد. از این پس می‌توانید با رمز هم وارد شوید.']);
} catch (PDOException $e) {
    error_log('Change Password Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
