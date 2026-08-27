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

$transactionId = (int)postParam('transaction_id');
$type = postParam('type');
$rawAmount = postParam('amount');
$title = postParam('title');
$note = postParam('note');
$transactionDate = postParam('transaction_date');
$categoryId = postParam('category_id');

if ($transactionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه تراکنش نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM transactions WHERE id = :id');
$checkStmt->execute(['id' => $transactionId]);
$tx = $checkStmt->fetch();

if (!$tx) {
    jsonResponse(['success' => false, 'message' => 'تراکنش مورد نظر یافت نشد.'], 404);
}

// کنترل دسترسی سمت سرور: هر کاربر (حتی ادمین) فقط اجازه ویرایش تراکنش خودش را دارد
if ((int)$tx['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه ویرایش این تراکنش را ندارید.'], 403);
}

$errors = [];

if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = 'نوع تراکنش نامعتبر است.';
}

$amount = sanitizeAmount($rawAmount);
if ($amount <= 0) {
    $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ وارد شده بیش از حد بزرگ است.';
}

if ($title === '' || mb_strlen($title) > 255) {
    $errors[] = 'عنوان الزامی است و باید کمتر از ۲۵۵ کاراکتر باشد.';
}

if (!isValidDate($transactionDate)) {
    $errors[] = 'تاریخ وارد شده نامعتبر است.';
}

if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

$categoryIdValue = null;
if ($categoryId !== '') {
    $categoryIdValue = (int)$categoryId;
    if ($categoryIdValue <= 0) {
        $errors[] = 'دسته‌بندی انتخاب‌شده نامعتبر است.';
    }
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

if ($categoryIdValue !== null) {
    $catCheckStmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND type = :type AND is_active = 1');
    $catCheckStmt->execute(['id' => $categoryIdValue, 'type' => $type]);
    if (!$catCheckStmt->fetch()) {
        $categoryIdValue = null;
    }
}

try {
    $updateStmt = $pdo->prepare('
        UPDATE transactions
        SET category_id = :category_id, type = :type, amount = :amount, title = :title, note = :note, transaction_date = :transaction_date
        WHERE id = :id AND user_id = :user_id
    ');
    $updateStmt->execute([
        'category_id'      => $categoryIdValue,
        'type'             => $type,
        'amount'           => $amount,
        'title'            => $title,
        'note'             => $note !== '' ? $note : null,
        'transaction_date' => $transactionDate,
        'id'               => $transactionId,
        'user_id'          => Auth::userId(),
    ]);

    jsonResponse(['success' => true, 'message' => 'تراکنش با موفقیت ویرایش شد.']);
} catch (PDOException $e) {
    error_log('Update Transaction Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ویرایش تراکنش رخ داد.'], 500);
}
