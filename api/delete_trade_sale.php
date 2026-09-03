<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ قفلِ صفحه بدونِ این فقط تزئین است: کسی که آدرسِ اندپوینت را
//    بداند مستقیم صدایش می‌زند.
require_once __DIR__ . '/../includes/plan_gate.php';
apiRequirePlan('trades');

$userId = Auth::userId();
$saleId = (int)postParam('sale_id');
$pdo = Database::getConnection();

try {
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
    if ($st->rowCount() === 0) { jsonResponse(['success' => false, 'message' => 'فروش یافت نشد.'], 404); }
    jsonResponse(['success' => true, 'message' => 'فروش حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Trade Sale Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
