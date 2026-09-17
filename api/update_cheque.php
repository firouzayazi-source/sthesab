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

// ⛔ اینجا عمداً `apiRequirePlan()` نیست: این اندپوینت رکوردِ تازه
//    نمی‌سازد، فقط چیزی را که کاربر از قبل ثبت کرده ویرایش/تسویه/حذف
//    می‌کند. بستنش یعنی دفترِ کاربر روی واقعیتِ ماه‌ها پیش یخ می‌زند —
//    و دفترِ غلط از دفترِ نداشته بدتر است. قاعده ۲۶ در
//    `test_api_contract.php` این فهرست را بسته نگه می‌دارد.

$userId   = Auth::userId();
$chequeId = (int)postParam('cheque_id');

if ($chequeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id, direction FROM cheques WHERE id = :id');
$checkStmt->execute(['id' => $chequeId]);
$cheque = $checkStmt->fetch();

if (!$cheque) {
    jsonResponse(['success' => false, 'message' => 'چک مورد نظر یافت نشد.'], 404);
}

if ((int)$cheque['user_id'] !== $userId) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه ویرایش این چک را ندارید.'], 403);
}

$counterpartyName = postParam('counterparty_name');
$rawAmount        = postParam('amount');
$bankId           = postParam('bank_id');
$sayadiNumber     = postParam('sayadi_number');
$chequeNumber     = postParam('cheque_number');
$note             = postParam('note');
$dueDate          = postParam('due_date');

$errors = [];

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

$bankIdValue = null;
if ($bankId !== '' && (int)$bankId > 0) {
    $expectedScope = $cheque['direction'] === 'issued' ? 'mine' : 'external';
    $bankStmt = $pdo->prepare('SELECT id FROM banks WHERE id = :id AND user_id = :user_id AND scope = :scope');
    $bankStmt->execute(['id' => (int)$bankId, 'user_id' => $userId, 'scope' => $expectedScope]);
    if ($bankStmt->fetch()) {
        $bankIdValue = (int)$bankId;
    }
}

try {
    $stmt = $pdo->prepare('
        UPDATE cheques
        SET counterparty_name = :counterparty_name, amount = :amount, bank_id = :bank_id,
            sayadi_number = :sayadi_number, cheque_number = :cheque_number,
            note = :note, due_date = :due_date
        WHERE id = :id AND user_id = :user_id
    ');
    $stmt->execute([
        'counterparty_name' => $counterpartyName,
        'amount'            => $amount,
        'bank_id'           => $bankIdValue,
        'sayadi_number'     => $sayadiNumber !== '' ? toLatinDigits($sayadiNumber) : null,
        'cheque_number'     => $chequeNumber !== '' ? toLatinDigits($chequeNumber) : null,
        'note'              => $note !== '' ? $note : null,
        'due_date'          => $dueDate !== '' ? $dueDate : null,
        'id'                => $chequeId,
        'user_id'           => $userId,
    ]);

    jsonResponse(['success' => true, 'message' => 'چک با موفقیت ویرایش شد.']);
} catch (PDOException $e) {
    Log::error('api.update_cheque', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ویرایش رخ داد.'], 500);
}
