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

// ⛔ اینجا عمداً `apiRequirePlan()` نیست: این اندپوینت رکوردِ تازه
//    نمی‌سازد، فقط چیزی را که کاربر از قبل ثبت کرده ویرایش/تسویه/حذف
//    می‌کند. بستنش یعنی دفترِ کاربر روی واقعیتِ ماه‌ها پیش یخ می‌زند —
//    و دفترِ غلط از دفترِ نداشته بدتر است. قاعده ۲۶ در
//    `test_api_contract.php` این فهرست را بسته نگه می‌دارد.
require_once __DIR__ . '/../includes/undo.php';

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

// ⛔ عکس **پیش از** حذف، وگرنه چیزی برای برگرداندن نمی‌ماند.
$undo = Undo::capture('cheques', $chequeId, Auth::userId());
try {
    $stmt = $pdo->prepare('DELETE FROM cheques WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $chequeId, 'user_id' => Auth::userId()]);

    jsonResponse(['success' => true, 'message' => 'چک حذف شد.', 'undo_token' => $undo]);
} catch (PDOException $e) {
    Log::error('api.delete_cheque', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در حذف رخ داد.'], 500);
}
