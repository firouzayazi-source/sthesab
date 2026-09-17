<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();
$goalId = (int)postParam('goal_id');
$direction = postParam('direction'); // deposit | withdraw
$rawAmount = sanitizeAmount(postParam('amount'));
$note = postParam('note');
$date = postParam('entry_date');

$errors = [];
if (!in_array($direction, ['deposit', 'withdraw'], true)) { $errors[] = 'نوع تراکنش نامعتبر است.'; }
if ($rawAmount <= 0) { $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.'; }
if (!isValidDate($date)) { $errors[] = 'تاریخ نامعتبر است.'; }
if (mb_strlen($note) > 1000) { $errors[] = 'توضیحات بیش از حد طولانی است.'; }

$pdo = Database::getConnection();
$goalStmt = $pdo->prepare('SELECT id FROM savings_goals WHERE id = :id AND user_id = :u');
$goalStmt->execute(['id' => $goalId, 'u' => $userId]);
if (!$goalStmt->fetch()) { jsonResponse(['success' => false, 'message' => 'هدف یافت نشد.'], 404); }

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$signedAmount = $direction === 'withdraw' ? -$rawAmount : $rawAmount;

// برداشت نمی‌تواند از موجودی فعلی هدف بیشتر باشد
if ($direction === 'withdraw') {
    $curStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS c FROM savings_entries WHERE goal_id = :g');
    $curStmt->execute(['g' => $goalId]);
    $current = (int)$curStmt->fetch()['c'];
    if ($rawAmount > $current) {
        jsonResponse(['success' => false, 'message' => 'مبلغ برداشت از موجودی فعلی این هدف (' . formatMoney($current) . ' تومان) بیشتر است.'], 422);
    }
}

try {
    $stmt = $pdo->prepare('INSERT INTO savings_entries (goal_id, user_id, amount, note, entry_date) VALUES (:g, :u, :a, :n, :d)');
    $stmt->execute(['g' => $goalId, 'u' => $userId, 'a' => $signedAmount, 'n' => $note !== '' ? $note : null, 'd' => $date]);
    jsonResponse(['success' => true, 'message' => $direction === 'deposit' ? 'واریز ثبت شد.' : 'برداشت ثبت شد.']);
} catch (PDOException $e) {
    Log::error('api.add_savings_entry', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
