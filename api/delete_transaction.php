<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد حساب کاربری خود شوید.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}

Csrf::verifyOrFail(postParam('csrf_token'));

$transactionId = (int)postParam('transaction_id');

if ($transactionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه تراکنش نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM transactions WHERE id = :id');
$checkStmt->execute(['id' => $transactionId]);
$tx = $checkStmt->fetch();

if (!$tx) {
    jsonResponse(['success' => false, 'message' => 'تراکنش مورد نظر یافت نشد.'], 404);
}

// کنترل دسترسی سمت سرور: هر کاربر (حتی ادمین) فقط اجازه حذف تراکنش خودش را دارد
if ((int)$tx['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه حذف این تراکنش را ندارید.'], 403);
}

try {
    $deleteStmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
    $deleteStmt->execute(['id' => $transactionId, 'user_id' => Auth::userId()]);

    jsonResponse(['success' => true, 'message' => 'تراکنش با موفقیت حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Transaction Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف تراکنش رخ داد.'], 500);
}