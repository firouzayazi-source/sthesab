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

$userId   = Auth::userId();
$walletId = (int)postParam('wallet_id');
$name     = postParam('name');
$kind     = postParam('kind', 'cash');
$bankName = postParam('bank_name');
$last4    = toLatinDigits(postParam('card_last4'));
$color    = postParam('color', '#64748b');
$rawInit  = postParam('initial_balance');
$initNeg  = postParam('initial_negative') === '1';

$errors = [];

if ($name === '' || mb_strlen($name) > 100) {
    $errors[] = 'نام حساب الزامی است و باید کمتر از ۱۰۰ کاراکتر باشد.';
}
if (!in_array($kind, ['cash', 'bank', 'card', 'other'], true)) {
    $kind = 'cash';
}
if ($last4 !== '' && !preg_match('/^[0-9]{4}$/', $last4)) {
    $errors[] = 'چهار رقم آخر کارت باید دقیقاً ۴ رقم باشد.';
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    $color = '#64748b';
}
if (mb_strlen($bankName) > 100) {
    $errors[] = 'نام بانک بیش از حد طولانی است.';
}

$initial = sanitizeAmount($rawInit);
if ($initial > 999999999999) {
    $errors[] = 'موجودی اولیه بیش از حد بزرگ است.';
}
if ($initNeg) {
    $initial = -$initial;
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

try {
    if ($walletId > 0) {
        $own = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $walletId, 'u' => $userId]);
        if (!$own->fetch()) {
            jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404);
        }

        $stmt = $pdo->prepare('
            UPDATE wallets
            SET name = :name, kind = :kind, bank_name = :bank_name,
                card_last4 = :last4, color = :color, initial_balance = :init
            WHERE id = :id AND user_id = :u
        ');
        $stmt->execute([
            'name' => $name, 'kind' => $kind,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'last4' => $last4 !== '' ? $last4 : null,
            'color' => $color, 'init' => $initial,
            'id' => $walletId, 'u' => $userId,
        ]);

        jsonResponse(['success' => true, 'message' => 'حساب بروزرسانی شد.']);
    }

    $stmt = $pdo->prepare('
        INSERT INTO wallets (user_id, name, kind, bank_name, card_last4, color, initial_balance)
        VALUES (:u, :name, :kind, :bank_name, :last4, :color, :init)
    ');
    $stmt->execute([
        'u' => $userId, 'name' => $name, 'kind' => $kind,
        'bank_name' => $bankName !== '' ? $bankName : null,
        'last4' => $last4 !== '' ? $last4 : null,
        'color' => $color, 'init' => $initial,
    ]);

    jsonResponse(['success' => true, 'message' => 'حساب ساخته شد.']);
} catch (PDOException $e) {
    error_log('Save Wallet Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ذخیره حساب رخ داد.'], 500);
}
