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

// ⛔ قفلِ صفحه بدونِ این فقط تزئین است: کسی که آدرسِ اندپوینت را
//    بداند مستقیم صدایش می‌زند.
require_once __DIR__ . '/../includes/plan_gate.php';
apiRequirePlan('cheques');

$chequeId = (int)postParam('cheque_id');
if ($chequeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM cheques WHERE id = :id');
$checkStmt->execute(['id' => $chequeId]);
$cheque = $checkStmt->fetch();

if (!$cheque) {
    jsonResponse(['success' => false, 'message' => 'چک یافت نشد.'], 404);
}

if ((int)$cheque['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه حذف این چک را ندارید.'], 403);
}

try {
    $stmt = $pdo->prepare('DELETE FROM cheques WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $chequeId, 'user_id' => Auth::userId()]);

    jsonResponse(['success' => true, 'message' => 'چک حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Cheque Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
}
