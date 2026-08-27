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

$userId      = Auth::userId();
$assetTypeId = (int)postParam('asset_type_id');
$rawQuantity = toLatinDigits(postParam('quantity'));
$rawPrice    = postParam('unit_price');
$note        = postParam('note');
$entryDate   = postParam('entry_date');

$errors = [];

$quantity = (float)preg_replace('/[^0-9.]/', '', $rawQuantity);
if ($quantity <= 0) {
    $errors[] = 'مقدار باید بزرگ‌تر از صفر باشد.';
}
if ($quantity > 99999999) {
    $errors[] = 'مقدار وارد شده بیش از حد بزرگ است.';
}

if (!isValidDate($entryDate)) {
    $errors[] = 'تاریخ نامعتبر است.';
}

if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

$typeStmt = $pdo->prepare('SELECT id FROM asset_types WHERE id = :id AND user_id = :user_id');
$typeStmt->execute(['id' => $assetTypeId, 'user_id' => $userId]);
if (!$typeStmt->fetch()) {
    jsonResponse(['success' => false, 'message' => 'نوع دارایی نامعتبر است.'], 422);
}

$unitPrice = sanitizeAmount($rawPrice);
$unitPriceValue = $unitPrice > 0 ? $unitPrice : null;

try {
    $stmt = $pdo->prepare('
        INSERT INTO assets (user_id, asset_type_id, quantity, unit_price, note, entry_date)
        VALUES (:user_id, :asset_type_id, :quantity, :unit_price, :note, :entry_date)
    ');
    $stmt->execute([
        'user_id'       => $userId,
        'asset_type_id' => $assetTypeId,
        'quantity'      => $quantity,
        'unit_price'    => $unitPriceValue,
        'note'          => $note !== '' ? $note : null,
        'entry_date'    => $entryDate,
    ]);

    jsonResponse(['success' => true, 'message' => 'دارایی ثبت شد.']);
} catch (PDOException $e) {
    error_log('Add Asset Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت رخ داد.'], 500);
}
