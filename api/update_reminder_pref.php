<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر

if (!tableExists('notification_prefs')) {
    jsonResponse(['success' => false,
        'message' => 'جدول یادآوری هنوز ساخته نشده. روی سرور:  bash deploy/migrate.sh --apply'], 500);
}

$on = postParam('email_on') === '1';

// فهرستِ مقدارهای مجاز فقط یک جا تعریف می‌شود (Auth::SESSION_WINDOWS هم
// همین الگو را دارد): فهرستِ دوم یعنی گزینه‌ای که کاربر می‌بیند هنگام
// ذخیره بی‌سروصدا به پیش‌فرض برمی‌گردد.
$days = (int)postParam('days_before');
if (!in_array($days, REMINDER_DAYS, true)) { $days = 3; }

try {
    Database::getConnection()->prepare(
        'INSERT INTO notification_prefs (user_id, email_on, days_before)
         VALUES (:u, :e, :d)
         ON DUPLICATE KEY UPDATE email_on = VALUES(email_on), days_before = VALUES(days_before)'
    )->execute(['u' => $userId, 'e' => $on ? 1 : 0, 'd' => $days]);
} catch (PDOException $e) {
    Log::error('api.reminder_pref', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ذخیره رخ داد.'], 500);
}

jsonResponse(['success' => true, 'message' => 'ذخیره شد.']);
