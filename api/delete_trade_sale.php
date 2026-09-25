<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/trade_credit.php';

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

$userId = Auth::userId();
$saleId = (int)postParam('sale_id');
$pdo = Database::getConnection();

try {
    $pdo->beginTransaction();
    // طلبِ نسیه‌ی همین فروش پس گرفته می‌شود — مگر پرداخت داشته باشد
    $kept = tradeCreditAvailable() && tradeCreditRelease($pdo, $userId, 'sale', $saleId) === 'kept' ? 1 : 0;

    // اول تراکنش سودِ پیوندی، بعد خود فروش
    try {
        $link = $pdo->prepare('SELECT profit_tx_id FROM trade_sales WHERE id = :id AND user_id = :u');
        $link->execute(['id' => $saleId, 'u' => $userId]);
        $txId = $link->fetchColumn();
        if ($txId) {
            $pdo->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :u')
                ->execute(['id' => (int)$txId, 'u' => $userId]);
        }
    } catch (PDOException $e) { /* ستون پیوند هنوز نیست */ }

    $st = $pdo->prepare('DELETE FROM trade_sales WHERE id = :id AND user_id = :u');
    $st->execute(['id' => $saleId, 'u' => $userId]);
    if ($st->rowCount() === 0) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'فروش یافت نشد.'], 404); }
    $pdo->commit();
    jsonResponse(['success' => true, 'message' => 'فروش حذف شد.' . tradeCreditKeptNote($kept)]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Log::error('api.delete_trade_sale', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
