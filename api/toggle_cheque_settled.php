<?php
/**
 * پاس شدن / برگشت از پاس شدنِ یک چک.
 *
 * پاس شدن چک یعنی پول واقعاً جابه‌جا شده، پس باید معلوم باشد به کدام
 * حساب رفت (چک دریافتی) یا از کدام حساب برداشته شد (چک صادره). حساب در
 * `cheques.settle_wallet_id` می‌نشیند و `walletBalances()` همان‌جا
 * جمعش می‌کند — عمداً ردیف تراکنش ساخته نمی‌شود، چون وصول یک چکِ طلب
 * درآمد نیست و نباید گزارش درآمد/هزینه را بالا ببرد.
 */
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
$chequeId = (int)postParam('cheque_id');
if ($chequeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id, direction, amount, is_settled FROM cheques WHERE id = :id');
$checkStmt->execute(['id' => $chequeId]);
$cheque = $checkStmt->fetch();

if (!$cheque) {
    jsonResponse(['success' => false, 'message' => 'چک یافت نشد.'], 404);
}

if ((int)$cheque['user_id'] !== $userId) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه تغییر این چک را ندارید.'], 403);
}

$newStatus = (int)$cheque['is_settled'] === 1 ? 0 : 1;

// ستون با migration_money_links می‌آید؛ روی نصبی که هنوز اجرا نشده،
// رفتار قبلی (فقط تیک، بدون حساب) حفظ می‌شود.
$hasWalletCol = tableHasColumn('cheques', 'settle_wallet_id');
$walletId = null;
$walletName = '';
if ($hasWalletCol && $newStatus === 1) {
    $walletId = resolveWalletId($userId, postParam('wallet_id'));
    if ($walletId !== null) {
        $wn = $pdo->prepare('SELECT name FROM wallets WHERE id = :id AND user_id = :u');
        $wn->execute(['id' => $walletId, 'u' => $userId]);
        $walletName = (string)$wn->fetchColumn();
    }
}

try {
    if ($hasWalletCol) {
        $stmt = $pdo->prepare(
            'UPDATE cheques SET is_settled = :status, settled_at = :settled_at, settle_wallet_id = :w
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'status'     => $newStatus,
            'settled_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
            'w'          => $newStatus === 1 ? $walletId : null,
            'id'         => $chequeId,
            'user_id'    => $userId,
        ]);
    } else {
        $stmt = $pdo->prepare('UPDATE cheques SET is_settled = :status, settled_at = :settled_at WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'status'     => $newStatus,
            'settled_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
            'id'         => $chequeId,
            'user_id'    => $userId,
        ]);
    }

    if ($newStatus === 1) {
        $verb = $cheque['direction'] === 'received' ? 'به' : 'از';
        $message = $walletName !== ''
            ? "چک پاس شد و مبلغش {$verb} «{$walletName}» اعمال شد."
            : 'چک پاس شد و به بایگانی رفت.';
    } else {
        $message = 'از بایگانی خارج شد و اثرش روی موجودی حساب برداشته شد.';
    }

    jsonResponse([
        'success'    => true,
        'is_settled' => $newStatus,
        'message'    => $message,
    ]);
} catch (PDOException $e) {
    error_log('Toggle Cheque Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
