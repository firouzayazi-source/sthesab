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

$debtId = (int)postParam('debt_id');
$counterpartyName = postParam('counterparty_name');
$rawAmount = postParam('amount');
$note = postParam('note');
$entryDate = postParam('entry_date');
$dueDate = postParam('due_date');

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
    jsonResponse(['success' => false, 'message' => 'شما اجازه ویرایش این مورد را ندارید.'], 403);
}

$errors = [];

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

try {
    $stmt = $pdo->prepare('
        UPDATE debts
        SET counterparty_name = :counterparty_name, amount = :amount, note = :note,
            entry_date = :entry_date, due_date = :due_date
        WHERE id = :id AND user_id = :user_id
    ');
    $stmt->execute([
        'counterparty_name' => $counterpartyName,
        'amount'            => $amount,
        'note'              => $note !== '' ? $note : null,
        'entry_date'        => $entryDate,
        'due_date'          => $dueDate,
        'id'                => $debtId,
        'user_id'           => Auth::userId(),
    ]);

    jsonResponse(['success' => true, 'message' => 'با موفقیت ویرایش شد.']);
} catch (PDOException $e) {
    error_log('Update Debt Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ویرایش رخ داد.'], 500);
}
