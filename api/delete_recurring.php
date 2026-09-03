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
apiRequirePlan('recurring');

$userId = Auth::userId();
$id = (int)postParam('recurring_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id FROM recurring_transactions WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
if (!$stmt->fetch()) { jsonResponse(['success' => false, 'message' => 'یافت نشد.'], 404); }

try {
    // حذف قانون؛ تراکنش‌های قبلاً ساخته‌شده باقی می‌مانند (فقط اشاره‌شان به این قانون پاک می‌شود)
    $del = $pdo->prepare('DELETE FROM recurring_transactions WHERE id = :id AND user_id = :u');
    $del->execute(['id' => $id, 'u' => $userId]);
    invalidateRecurringCache($userId);
    jsonResponse(['success' => true, 'message' => 'حذف شد.']);
} catch (PDOException $e) {
    error_log('Delete Recurring Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
