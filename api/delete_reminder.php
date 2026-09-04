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
$id     = (int)postParam('id');
if ($id <= 0) { jsonResponse(['success' => false, 'message' => 'یادآور نامعتبر است.'], 422); }

// ⚠ شرطِ `user_id` روی خودِ DELETE است — بدونِ آن هر کسی با شناسه‌ی
//   دیگری یادآورِ کاربرِ دیگری را پاک می‌کرد.
$stmt = Database::getConnection()->prepare('DELETE FROM reminders WHERE id = :i AND user_id = :u');
$stmt->execute(['i' => $id, 'u' => $userId]);

jsonResponse(['success' => true, 'message' => 'یادآور حذف شد.']);
