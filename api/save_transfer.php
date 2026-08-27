<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId     = Auth::userId();
$transferId = (int)postParam('transfer_id');
$fromId     = (int)postParam('from_wallet_id');
$toId       = (int)postParam('to_wallet_id');
$amount     = sanitizeAmount(postParam('amount'));
$fee        = sanitizeAmount(postParam('fee'));
$note       = postParam('note');
$date       = postParam('transfer_date');

$errors = [];

if ($fromId <= 0 || $toId <= 0) {
    $errors[] = 'حساب مبدأ و مقصد را انتخاب کنید.';
} elseif ($fromId === $toId) {
    $errors[] = 'حساب مبدأ و مقصد نمی‌توانند یکی باشند.';
}
if ($amount <= 0) {
    $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ بیش از حد بزرگ است.';
}
if (!isValidDate($date)) {
    $errors[] = 'تاریخ نامعتبر است.';
}
if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات بیش از حد طولانی است.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

// هر دو حساب باید متعلق به همین کاربر باشند
$chk = $pdo->prepare('SELECT COUNT(*) AS c FROM wallets WHERE user_id = :u AND id IN (:a, :b)');
$chk->execute(['u' => $userId, 'a' => $fromId, 'b' => $toId]);
if ((int)$chk->fetch()['c'] !== 2) {
    jsonResponse(['success' => false, 'message' => 'حساب انتخاب‌شده معتبر نیست.'], 403);
}

try {
    if ($transferId > 0) {
        $own = $pdo->prepare('SELECT id FROM transfers WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $transferId, 'u' => $userId]);
        if (!$own->fetch()) {
            jsonResponse(['success' => false, 'message' => 'انتقال یافت نشد.'], 404);
        }

        $stmt = $pdo->prepare('
            UPDATE transfers
            SET from_wallet_id = :f, to_wallet_id = :t, amount = :a, fee = :fee,
                note = :note, transfer_date = :d
            WHERE id = :id AND user_id = :u
        ');
        $stmt->execute([
            'f' => $fromId, 't' => $toId, 'a' => $amount, 'fee' => $fee,
            'note' => $note !== '' ? $note : null, 'd' => $date,
            'id' => $transferId, 'u' => $userId,
        ]);

        jsonResponse(['success' => true, 'message' => 'انتقال ویرایش شد.']);
    }

    $stmt = $pdo->prepare('
        INSERT INTO transfers (user_id, from_wallet_id, to_wallet_id, amount, fee, note, transfer_date)
        VALUES (:u, :f, :t, :a, :fee, :note, :d)
    ');
    $stmt->execute([
        'u' => $userId, 'f' => $fromId, 't' => $toId, 'a' => $amount, 'fee' => $fee,
        'note' => $note !== '' ? $note : null, 'd' => $date,
    ]);

    jsonResponse(['success' => true, 'message' => 'انتقال ثبت شد.']);
} catch (PDOException $e) {
    error_log('Save Transfer Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت انتقال رخ داد.'], 500);
}
