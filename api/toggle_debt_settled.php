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

$debtId = (int)postParam('debt_id');

if ($debtId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id, is_settled FROM debts WHERE id = :id');
$checkStmt->execute(['id' => $debtId]);
$debt = $checkStmt->fetch();

if (!$debt) {
    jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
}

if ((int)$debt['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه تغییر این مورد را ندارید.'], 403);
}

$newStatus = (int)$debt['is_settled'] === 1 ? 0 : 1;

try {
    $stmt = $pdo->prepare('
        UPDATE debts
        SET is_settled = :status, settled_at = :settled_at
        WHERE id = :id AND user_id = :user_id
    ');
    $stmt->execute([
        'status'     => $newStatus,
        'settled_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
        'id'         => $debtId,
        'user_id'    => Auth::userId(),
    ]);

    jsonResponse([
        'success'    => true,
        'is_settled' => $newStatus,
        'message'    => $newStatus === 1 ? 'به‌عنوان تسویه‌شده علامت خورد.' : 'علامت تسویه برداشته شد.',
    ]);
} catch (PDOException $e) {
    error_log('Toggle Debt Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
