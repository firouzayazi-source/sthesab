<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

// ⚠ فهرست مجاز از خودِ Auth می‌آید، نه از یک آرایه‌ی تکراری اینجا.
//   دو فهرست جدا دیر یا زود از هم دور می‌افتند و آن‌وقت گزینه‌ای که در
//   پروفایل دیده می‌شود، هنگام ذخیره بی‌سروصدا به پیش‌فرض برمی‌گردد.
$minutes = (int)postParam('session_minutes');
if (!Auth::isValidSessionWindow($minutes)) { $minutes = 0; }

try {
    $upd = Database::getConnection()->prepare('UPDATE users SET session_minutes = :m WHERE id = :id');
    $upd->execute(['m' => $minutes, 'id' => $userId]);

    $_SESSION['session_minutes'] = $minutes;
    $_SESSION['last_seen']       = time();

    // مهلتِ تازه باید همین حالا روی کوکیِ همین دستگاه هم بنشیند، وگرنه
    // کاربر «یک ماه» را انتخاب می‌کند و کوکی‌اش با سررسیدِ قدیمی می‌ماند
    // — تا ورودِ بعدی هیچ اثری نمی‌بیند.
    Auth::refreshTrustForCurrentDevice();

    jsonResponse(['success' => true, 'message' => 'ذخیره شد.']);
} catch (PDOException $e) {
    Log::error('api.session_pref', $e);
    jsonResponse(['success' => false, 'message' => 'ابتدا migration_session_window.sql را اجرا کنید.'], 500);
}
