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
require_once __DIR__ . '/../includes/undo.php';
apiRequirePlan('debts');

$debtId = (int)postParam('debt_id');

if ($debtId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM debts WHERE id = :id');
$checkStmt->execute(['id' => $debtId]);
$debt = $checkStmt->fetch();

if (!$debt) {
    jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
}

if ((int)$debt['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه حذف این مورد را ندارید.'], 403);
}

// ⛔ عکس **پیش از** حذف، وگرنه چیزی برای برگرداندن نمی‌ماند.
$undo = Undo::capture('debts', $debtId, Auth::userId());
try {
    $stmt = $pdo->prepare('DELETE FROM debts WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $debtId, 'user_id' => Auth::userId()]);

    jsonResponse(['success' => true, 'message' => 'با موفقیت حذف شد.', 'undo_token' => $undo]);
} catch (PDOException $e) {
    error_log('Delete Debt Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
}
