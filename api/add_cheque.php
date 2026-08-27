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

$userId = Auth::userId();

$direction        = postParam('direction');
$counterpartyName = postParam('counterparty_name');
$rawAmount        = postParam('amount');
$bankId           = postParam('bank_id');
$sayadiNumber     = postParam('sayadi_number');
$chequeNumber     = postParam('cheque_number');
$note             = postParam('note');
$dueDate          = postParam('due_date');

$errors = [];

if (!in_array($direction, ['received', 'issued'], true)) {
    $errors[] = 'نوع چک نامعتبر است.';
}

// فقط این دو مورد اجباری هستند
if ($counterpartyName === '' || mb_strlen($counterpartyName) > 150) {
    $errors[] = 'نام شخص الزامی است و باید کمتر از ۱۵۰ کاراکتر باشد.';
}

$amount = sanitizeAmount($rawAmount);
if ($amount <= 0) {
    $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ وارد شده بیش از حد بزرگ است.';
}

// موارد اختیاری
if ($dueDate !== '' && !isValidDate($dueDate)) {
    $errors[] = 'تاریخ سررسید نامعتبر است.';
}
if (mb_strlen($sayadiNumber) > 30) {
    $errors[] = 'شماره صیادی نباید بیشتر از ۳۰ کاراکتر باشد.';
}
if (mb_strlen($chequeNumber) > 30) {
    $errors[] = 'شماره چک نباید بیشتر از ۳۰ کاراکتر باشد.';
}
if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

// بانک اختیاری است، ولی اگر داده شد باید مال همین کاربر و از scope درست باشد
$bankIdValue = null;
if ($bankId !== '' && (int)$bankId > 0) {
    $expectedScope = $direction === 'issued' ? 'mine' : 'external';
    $bankStmt = $pdo->prepare('SELECT id FROM banks WHERE id = :id AND user_id = :user_id AND scope = :scope');
    $bankStmt->execute(['id' => (int)$bankId, 'user_id' => $userId, 'scope' => $expectedScope]);
    if ($bankStmt->fetch()) {
        $bankIdValue = (int)$bankId;
    }
}

try {
    $stmt = $pdo->prepare('
        INSERT INTO cheques
            (user_id, direction, counterparty_name, amount, bank_id, sayadi_number, cheque_number, note, due_date)
        VALUES
            (:user_id, :direction, :counterparty_name, :amount, :bank_id, :sayadi_number, :cheque_number, :note, :due_date)
    ');
    $stmt->execute([
        'user_id'           => $userId,
        'direction'         => $direction,
        'counterparty_name' => $counterpartyName,
        'amount'            => $amount,
        'bank_id'           => $bankIdValue,
        'sayadi_number'     => $sayadiNumber !== '' ? toLatinDigits($sayadiNumber) : null,
        'cheque_number'     => $chequeNumber !== '' ? toLatinDigits($chequeNumber) : null,
        'note'              => $note !== '' ? $note : null,
        'due_date'          => $dueDate !== '' ? $dueDate : null,
    ]);

    jsonResponse(['success' => true, 'message' => 'چک با موفقیت ثبت شد.']);
} catch (PDOException $e) {
    error_log('Add Cheque Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت چک رخ داد.'], 500);
}
