<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/undo.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}

Csrf::verifyOrFail(postParam('csrf_token'));

$assetId = (int)postParam('asset_id');
if ($assetId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM assets WHERE id = :id');
$checkStmt->execute(['id' => $assetId]);
$asset = $checkStmt->fetch();

if (!$asset) {
    jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
}

if ((int)$asset['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه حذف این مورد را ندارید.'], 403);
}

// ⛔ عکس **پیش از** حذف، وگرنه چیزی برای برگرداندن نمی‌ماند.
$undo = Undo::capture('assets', $assetId, Auth::userId());
try {
    $stmt = $pdo->prepare('DELETE FROM assets WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $assetId, 'user_id' => Auth::userId()]);

    jsonResponse(['success' => true, 'message' => 'حذف شد.', 'undo_token' => $undo]);
} catch (PDOException $e) {
    Log::error('api.delete_asset', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
}
