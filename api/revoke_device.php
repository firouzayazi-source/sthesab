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

if (postParam('all') === '1') {
    Auth::revokeAllDevices($userId);
    jsonResponse(['success' => true, 'message' => 'همه دستگاه‌ها حذف شدند.']);
}

$id = (int)postParam('device_id');
if ($id <= 0) { jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422); }

Auth::revokeDevice($id, $userId);
jsonResponse(['success' => true, 'message' => 'دستگاه حذف شد.']);
