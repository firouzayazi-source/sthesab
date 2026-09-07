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

$userId = Auth::userId();
$id = (int)postParam('recurring_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT * FROM recurring_transactions WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$r = $stmt->fetch();
if (!$r) { jsonResponse(['success' => false, 'message' => 'یافت نشد.'], 404); }

try {
    // بدون ساختن تراکنش، فقط به سررسید بعدی می‌رویم — برای «این دوره را رد کن»
    $nextDue = advanceRecurringDate($r['next_due_date'], $r['frequency'], (int)$r['interval_count']);
    $stillActive = !($r['end_date'] !== null && $nextDue > $r['end_date']);

    $upd = $pdo->prepare('UPDATE recurring_transactions SET next_due_date = :n, is_active = :a WHERE id = :id AND user_id = :u');
    $upd->execute(['n' => $nextDue, 'a' => $stillActive ? 1 : 0, 'id' => $id, 'u' => $userId]);

    invalidateRecurringCache($userId);
    jsonResponse(['success' => true, 'message' => 'این دوره رد شد.']);
} catch (PDOException $e) {
    error_log('Skip Recurring Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
