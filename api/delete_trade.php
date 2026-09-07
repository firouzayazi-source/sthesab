<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ اینجا عمداً `apiRequirePlan()` نیست: این اندپوینت رکوردِ تازه
//    نمی‌سازد، فقط چیزی را که کاربر از قبل ثبت کرده ویرایش/تسویه/حذف
//    می‌کند. بستنش یعنی دفترِ کاربر روی واقعیتِ ماه‌ها پیش یخ می‌زند —
//    و دفترِ غلط از دفترِ نداشته بدتر است. قاعده ۲۶ در
//    `test_api_contract.php` این فهرست را بسته نگه می‌دارد.

$userId  = Auth::userId();
$tradeId = (int)postParam('trade_id');
$pdo = Database::getConnection();

try {
    // تراکنش‌های سودِ فروش‌ها جدول جدایی‌اند و CASCADE شاملشان نمی‌شود
    deleteTradeProfitTransactions($userId, $tradeId);
    // فروش‌ها با ON DELETE CASCADE خودشان پاک می‌شوند
    $st = $pdo->prepare('DELETE FROM trades WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $tradeId, 'u' => $userId]);
    if ($st->rowCount() === 0) { jsonResponse(['success' => false, 'message' => 'معامله یافت نشد.'], 404); }
    jsonResponse(['success' => true, 'message' => 'معامله حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Trade Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
