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

$userId = Auth::userId();
$id = (int)postParam('wallet_id');

$pdo = Database::getConnection();
$stmt = $pdo->prepare('SELECT id, is_active FROM wallets WHERE id = :id AND user_id = :u');
$stmt->execute(['id' => $id, 'u' => $userId]);
$w = $stmt->fetch();

if (!$w) {
    jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404);
}

$new = (int)$w['is_active'] === 1 ? 0 : 1;
// ⛔ آخرین حسابِ فعال غیرفعال نمی‌شود — همان سدِ «حداقل یک حساب»ِ `delete_wallet`.
//    بازرسیِ مهر ۱۴۰۵: `defaultWalletId()` فقط حسابِ فعال می‌بیند، پس بعد از آن هر
//    تراکنش و پرداختِ بدهی بی‌حساب ثبت می‌شد و در هیچ موجودی‌ای نمی‌آمد.
if ($new === 0) {
    $act = $pdo->prepare('SELECT COUNT(*) FROM wallets WHERE user_id = :u AND is_active = 1 AND id <> :id');
    $act->execute(['u' => $userId, 'id' => $id]);
    if ((int)$act->fetchColumn() === 0) {
        jsonResponse(['success' => false, 'message' => 'این تنها حسابِ فعالِ شماست؛ اول حسابِ دیگری بسازید یا فعال کنید.'], 422);
    }
}
$upd = $pdo->prepare('UPDATE wallets SET is_active = :s WHERE id = :id AND user_id = :u');
$upd->execute(['s' => $new, 'id' => $id, 'u' => $userId]);

jsonResponse([
    'success' => true,
    'is_active' => $new,
    'message' => $new === 1 ? 'حساب فعال شد.' : 'حساب غیرفعال شد.',
]);
