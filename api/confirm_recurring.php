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
// امکان اصلاح مبلغ هنگام تأیید (مثلاً قبض متغیر)
$overrideAmount = postParam('amount');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT * FROM recurring_transactions WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$r = $stmt->fetch();
if (!$r) { jsonResponse(['success' => false, 'message' => 'یافت نشد.'], 404); }
if ($r['next_due_date'] > today()) { jsonResponse(['success' => false, 'message' => 'این مورد هنوز سررسید نشده است.'], 422); }

$amount = (int)$r['amount'];
if ($overrideAmount !== '' && $overrideAmount !== null) {
    $sanitized = sanitizeAmount($overrideAmount);
    if ($sanitized > 0) { $amount = $sanitized; }
}

try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare('
        INSERT INTO transactions (user_id, category_id, wallet_id, recurring_id, type, amount, title, note, transaction_date)
        VALUES (:u, :cat, :wal, :rid, :type, :amount, :title, :note, :date)
    ');
    $ins->execute([
        'u' => $userId, 'cat' => $r['category_id'], 'wal' => $r['wallet_id'], 'rid' => $r['id'],
        'type' => $r['type'], 'amount' => $amount, 'title' => $r['title'], 'note' => $r['note'],
        'date' => $r['next_due_date'],
    ]);

    $nextDue = advanceRecurringDate($r['next_due_date'], $r['frequency'], (int)$r['interval_count']);
    $stillActive = !($r['end_date'] !== null && $nextDue > $r['end_date']);

    $upd = $pdo->prepare('UPDATE recurring_transactions SET next_due_date = :n, is_active = :a WHERE id = :id');
    $upd->execute(['n' => $nextDue, 'a' => $stillActive ? 1 : 0, 'id' => $r['id']]);

    $pdo->commit();
    invalidateRecurringCache($userId);
    jsonResponse(['success' => true, 'message' => 'ثبت شد.']);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Confirm Recurring Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
