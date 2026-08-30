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

$type = postParam('type');
$rawAmount = postParam('amount');
$title = postParam('title');
$note = postParam('note');
$transactionDate = postParam('transaction_date');
$categoryId = postParam('category_id');
$walletId   = postParam('wallet_id');

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

$pdo = Database::getConnection();

// اگر دسته‌بندی انتخاب شده، بررسی شود که واقعاً وجود دارد و با نوع تراکنش هم‌خوانی دارد
if ($categoryIdValue !== null) {
    $catCheckStmt = $pdo->prepare(
        'SELECT id FROM categories
         WHERE id = :id AND type = :type AND is_active = 1 AND ' . categoryScopeSql()
    );
    $catCheckStmt->execute(['id' => $categoryIdValue, 'type' => $type] + categoryScopeParams($userId));
    if (!$catCheckStmt->fetch()) {
        $categoryIdValue = null;
    }
}

try {
    $pdo->beginTransaction();

    // حساب باید متعلق به همین کاربر باشد؛ در غیر این صورت نادیده گرفته می‌شود
    $walletIdValue = null;
    if ($walletId !== '' && (int)$walletId > 0) {
        $wchk = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
        $wchk->execute(['id' => (int)$walletId, 'u' => Auth::userId()]);
        if ($wchk->fetch()) {
            $walletIdValue = (int)$walletId;
        }
    }

    $insertStmt = $pdo->prepare('
        INSERT INTO transactions (user_id, category_id, wallet_id, type, amount, title, note, transaction_date)
        VALUES (:user_id, :category_id, :wallet_id, :type, :amount, :title, :note, :transaction_date)
    ');

    $insertStmt->execute([
        'user_id'          => Auth::userId(),
        'category_id'      => $categoryIdValue,
        'wallet_id'        => $walletIdValue,
        'type'             => $type,
        'amount'           => $amount,
        'title'            => $title,
        'note'             => $note !== '' ? $note : null,
        'transaction_date' => $transactionDate,
    ]);

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => 'تراکنش با موفقیت ثبت شد.',
        'today'   => today(),
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Add Transaction Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت تراکنش رخ داد. دوباره تلاش کنید.'], 500);
}