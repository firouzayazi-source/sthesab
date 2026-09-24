<?php
/**
 * قلم‌های صفحه‌ی خانه — روشن/خاموش، برای هر کاربر (پروفایل → «صفحه‌ی خانه»).
 *
 * ⛔ ورودی فهرستِ **روشن‌ها**ست (`on[]`) و خاموش‌ها از `HOME_WIDGETS`
 *    حساب می‌شوند، نه برعکس: فرم همه‌ی کلیدها را می‌شناسد، پس «هر کلیدی
 *    که تیک ندارد خاموش است» تنها تعبیرِ بی‌ابهام است. و ذخیره فقط از
 *    `saveHomeHidden()` می‌گذرد که کلیدِ ناشناخته را دور می‌ریزد.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

if (!tableHasColumn('users', 'home_hidden')) {
    jsonResponse(['success' => false, 'message' => 'ستونِ این تنظیم هنوز ساخته نشده. روی سرور:  bash deploy/migrate.sh --apply'], 500);
}

$on = $_POST['on'] ?? [];
if (!is_array($on)) { $on = []; }
$on = array_map('strval', $on);

$hidden = array_values(array_diff(array_keys(HOME_WIDGETS), $on));

try {
    saveHomeHidden($userId, $hidden);
    jsonResponse([
        'success' => true,
        'hidden'  => homeHiddenWidgets($userId),
        'message' => $hidden === [] ? 'همه‌ی قلم‌های خانه روشن‌اند.' : 'ذخیره شد.',
    ]);
} catch (PDOException $e) {
    Log::error('api.save_home_widgets', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
