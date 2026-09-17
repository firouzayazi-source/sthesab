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

$userId  = Auth::userId();
$assetId = (int)postParam('asset_id');

if ($assetId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM assets WHERE id = :id');
$checkStmt->execute(['id' => $assetId]);
$asset = $checkStmt->fetch();

if (!$asset) {
    jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
}

if ((int)$asset['user_id'] !== $userId) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه ویرایش این مورد را ندارید.'], 403);
}

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

$unitPrice = sanitizeAmount($rawPrice);
$unitPriceValue = $unitPrice > 0 ? $unitPrice : null;

try {
    $stmt = $pdo->prepare('
        UPDATE assets SET quantity = :quantity, unit_price = :unit_price, note = :note, entry_date = :entry_date
        WHERE id = :id AND user_id = :user_id
    ');
    $stmt->execute([
        'quantity'   => $quantity,
        'unit_price' => $unitPriceValue,
        'note'       => $note !== '' ? $note : null,
        'entry_date' => $entryDate,
        'id'         => $assetId,
        'user_id'    => $userId,
    ]);

    jsonResponse(['success' => true, 'message' => 'با موفقیت ویرایش شد.']);
} catch (PDOException $e) {
    Log::error('api.update_asset', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ویرایش رخ داد.'], 500);
}
