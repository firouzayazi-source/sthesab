<?php
/**
 * حذفِ کاملِ حساب و همه‌ی داده‌ی کاربر.
 *
 * ⛔ سه سد، و هیچ‌کدام اختیاری نیست:
 *
 *  ۱. **رمز فعلی.** بدونش، هر کسی که چند دقیقه به گوشیِ باز دسترسی
 *     داشته باشد می‌تواند سال‌ها دفترِ مالیِ کسی را پاک کند.
 *  ۲. **تایپِ عبارتِ تأیید.** یک دکمه‌ی «مطمئنی؟» با یک لغزشِ انگشت رد
 *     می‌شود؛ تایپ کردن نمی‌شود.
 *  ۳. **آخرین مدیر نمی‌تواند خودش را حذف کند.** وگرنه نصب بی‌مدیر
 *     می‌ماند و هیچ راهی جز خط فرمان باقی نمی‌گذارد.
 *
 * بازگشتی ندارد. متنِ صفحه هم همین را می‌گوید و پیش از آن، خروجی گرفتن
 * پیشنهاد می‌شود.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId  = Auth::userId();
$password = postParam('password');
$confirm  = trim(postParam('confirm'));

/** عبارتی که باید عیناً تایپ شود. */
const DELETE_PHRASE = 'حذف حساب';

if ($confirm !== DELETE_PHRASE) {
    jsonResponse([
        'success' => false,
        'message' => 'برای تأیید، عبارت «' . DELETE_PHRASE . '» را دقیقاً بنویسید.',
    ], 422);
}

$pdo = Database::getConnection();

$st = $pdo->prepare('SELECT username, password_hash FROM users WHERE id = :id');
$st->execute(['id' => $userId]);
$row = $st->fetch();
if (!$row || !password_verify($password, $row['password_hash'])) {
    jsonResponse(['success' => false, 'message' => 'رمز عبور اشتباه است.'], 403);
}

// سد ۳ — نصب نباید بی‌مدیر بماند.
if (Auth::isAdmin() && countOtherActiveAdmins($pdo, $userId) < 1) {
    jsonResponse([
        'success' => false,
        'message' => 'شما تنها مدیر فعال هستید. اول مدیر دیگری بسازید.',
    ], 409);
}

$res = deleteUserAccount($userId);
if (!$res['ok']) {
    error_log('Delete Account Error: ' . ($res['reason'] ?? ''));
    jsonResponse(['success' => false, 'message' => 'حذف انجام نشد. چیزی تغییر نکرد.'], 500);
}

// نشست و کوکیِ دستگاه هم باید بروند — ردیفشان از قبل پاک شده، ولی
// کوکیِ مرورگر هنوز روی دستگاه است.
Auth::logout();

jsonResponse([
    'success'  => true,
    'message'  => 'حساب و همه‌ی داده‌ی آن حذف شد.',
    'redirect' => APP_BASE_PATH . '/login.php',
]);
