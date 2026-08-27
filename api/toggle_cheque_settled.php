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

$chequeId = (int)postParam('cheque_id');
if ($chequeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id, is_settled FROM cheques WHERE id = :id');
$checkStmt->execute(['id' => $chequeId]);
$cheque = $checkStmt->fetch();

if (!$cheque) {
    jsonResponse(['success' => false, 'message' => 'چک یافت نشد.'], 404);
}

if ((int)$cheque['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه تغییر این چک را ندارید.'], 403);
}

$newStatus = (int)$cheque['is_settled'] === 1 ? 0 : 1;

try {
    $stmt = $pdo->prepare('UPDATE cheques SET is_settled = :status, settled_at = :settled_at WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'status'     => $newStatus,
        'settled_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
        'id'         => $chequeId,
        'user_id'    => Auth::userId(),
    ]);

    jsonResponse([
        'success'    => true,
        'is_settled' => $newStatus,
        'message'    => $newStatus === 1 ? 'چک پاس شد و به بایگانی رفت.' : 'از بایگانی خارج شد.',
    ]);
} catch (PDOException $e) {
    error_log('Toggle Cheque Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
