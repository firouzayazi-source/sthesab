<?php
/**
 * خوانده‌شدن و پاک کردنِ اعلان‌ها.
 *
 * ⚠ فقط همین‌ها می‌نویسند؛ **خواندنِ** فهرست کارِ `notifications.php`
 *   است که صفحه‌ی معمولی است.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$action = postParam('action', 'read');

if ($action === 'clear') {
    Notify::deleteAll($userId);
    jsonResponse(['success' => true, 'message' => 'اعلان‌ها پاک شدند.']);
}

Notify::markRead($userId, (int)postParam('id'));
jsonResponse(['success' => true, 'unread' => Notify::unreadCount($userId)]);
