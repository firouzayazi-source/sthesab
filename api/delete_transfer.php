<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/undo.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$id = (int)postParam('transfer_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id FROM transfers WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
if (!$stmt->fetch()) {
    jsonResponse(['success' => false, 'message' => 'انتقال یافت نشد.'], 404);
}

// ⛔ عکس **پیش از** حذف، وگرنه چیزی برای برگرداندن نمی‌ماند.
$undo = Undo::capture('transfers', $id, $userId);
try {
    // با حذف رکورد، موجودی هر دو حساب خودکار اصلاح می‌شود
    // چون موجودی همیشه از روی داده‌ها محاسبه می‌شود، نه ذخیره‌شده
    $del = $pdo->prepare('DELETE FROM transfers WHERE id = :id AND user_id = :u');
    $del->execute(['id' => $id, 'u' => $userId]);
    jsonResponse(['success' => true, 'message' => 'انتقال حذف شد.', 'undo_token' => $undo]);
} catch (PDOException $e) {
    error_log('Delete Transfer Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
}
