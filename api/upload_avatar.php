<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/avatar.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

// ⛔ همه‌ی سنجش و بازکشیِ تصویر در `saveUserAvatar()` است — همان که تنظیماتِ
//    فروشگاه هم صدا می‌زند (includes/avatar.php).
$res = saveUserAvatar((int)$userId, $_FILES['avatar'] ?? null);
if (!$res['ok']) {
    jsonResponse(['success' => false, 'message' => $res['message']], $res['code']);
}
jsonResponse(['success' => true, 'avatar_url' => $res['url'], 'message' => $res['message']]);
