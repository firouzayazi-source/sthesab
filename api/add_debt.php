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

$direction = postParam('direction');
$counterpartyName = postParam('counterparty_name');
$rawAmount = postParam('amount');
$note = postParam('note');
$entryDate = postParam('entry_date');
$dueDate = postParam('due_date');

$errors = [];

if (!in_array($direction, ['receivable', 'payable'], true)) {
    $errors[] = 'نوع نامعتبر است.';
}

if ($counterpartyName === '' || mb_strlen($counterpartyName) > 150) {
    $errors[] = 'نام طرف حساب الزامی است و باید کمتر از ۱۵۰ کاراکتر باشد.';
}

$amount = sanitizeAmount($rawAmount);
if ($amount <= 0) {
    $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ وارد شده بیش از حد بزرگ است.';
}

if (!isValidDate($entryDate)) {
    $errors[] = 'تاریخ ثبت نامعتبر است.';
}
if (!isValidDate($dueDate)) {
    $errors[] = 'تاریخ سررسید نامعتبر است.';
}

if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

try {
    $stmt = $pdo->prepare('
        INSERT INTO debts (user_id, direction, counterparty_name, amount, note, entry_date, due_date)
        VALUES (:user_id, :direction, :counterparty_name, :amount, :note, :entry_date, :due_date)
    ');
    $stmt->execute([
        'user_id'           => Auth::userId(),
        'direction'         => $direction,
        'counterparty_name' => $counterpartyName,
        'amount'            => $amount,
        'note'              => $note !== '' ? $note : null,
        'entry_date'        => $entryDate,
        'due_date'          => $dueDate,
    ]);

    jsonResponse(['success' => true, 'message' => 'با موفقیت ثبت شد.']);
} catch (PDOException $e) {
    error_log('Add Debt Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت رخ داد. دوباره تلاش کنید.'], 500);
}
