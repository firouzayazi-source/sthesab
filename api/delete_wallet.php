<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

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
$id = (int)postParam('wallet_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
if (!$stmt->fetch()) {
    jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404);
}

$used = walletUsageCount($id, $userId);
if ($used > 0) {
    jsonResponse([
        'success' => false,
        'message' => 'روی این حساب ' . toPersianDigits($used) . ' تراکنش یا انتقال ثبت شده و قابل حذف نیست. می‌توانید غیرفعالش کنید.',
    ], 409);
}

$cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM wallets WHERE user_id = :u');
$cnt->execute(['u' => $userId]);
if ((int)$cnt->fetch()['c'] <= 1) {
    jsonResponse(['success' => false, 'message' => 'حداقل یک حساب باید باقی بماند.'], 409);
}

try {
    $del = $pdo->prepare('DELETE FROM wallets WHERE id = :id AND user_id = :u');
    $del->execute(['id' => $id, 'u' => $userId]);
    jsonResponse(['success' => true, 'message' => 'حساب حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Wallet Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
}
