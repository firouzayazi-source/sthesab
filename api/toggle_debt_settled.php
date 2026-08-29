<?php
/**
 * تسویه / برگرداندنِ تسویه‌ی یک طلب یا بدهی.
 *
 * تسویه یعنی پول جابه‌جا شده، پس باید معلوم باشد از/به کدام حساب. همه‌ی
 * جابه‌جایی پولِ طلب و بدهی از یک مسیر می‌گذرد — جدول `debt_payments` —
 * چه پرداخت جزئی باشد چه تسویه‌ی کامل. زدن تیک «تسویه شد» یک پرداخت
 * برای *باقیمانده* می‌سازد و با `is_settlement = 1` علامتش می‌زند تا
 * برداشتن تیک بتواند دقیقاً همان را پس بگیرد و پرداخت‌های دستی کاربر
 * دست‌نخورده بمانند.
 *
 * مثل چک، عمداً ردیف تراکنش ساخته نمی‌شود: وصول طلب درآمد نیست.
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

$userId = Auth::userId();
$debtId = (int)postParam('debt_id');

if ($debtId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id, direction, amount, paid_amount, is_settled FROM debts WHERE id = :id');
$checkStmt->execute(['id' => $debtId]);
$debt = $checkStmt->fetch();

if (!$debt) {
    jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
}

if ((int)$debt['user_id'] !== $userId) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه تغییر این مورد را ندارید.'], 403);
}

$newStatus = (int)$debt['is_settled'] === 1 ? 0 : 1;

// ستون‌ها با migration_money_links می‌آیند؛ بدون آن‌ها رفتار قبلی
// (فقط تیک، بدون اثر روی موجودی) حفظ می‌شود.
$tracksWallet = tableHasColumn('debt_payments', 'wallet_id')
             && tableHasColumn('debt_payments', 'is_settlement');

$walletName = '';
$moved = 0;

try {
    $pdo->beginTransaction();

    if (!$tracksWallet) {
        $pdo->prepare('UPDATE debts SET is_settled = :s, settled_at = :sa WHERE id = :id AND user_id = :u')
            ->execute([
                's'  => $newStatus,
                'sa' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
                'id' => $debtId, 'u' => $userId,
            ]);
    } elseif ($newStatus === 1) {
        $remaining = (int)$debt['amount'] - (int)$debt['paid_amount'];
        if ($remaining > 0) {
            $walletId = resolveWalletId($userId, postParam('wallet_id'));
            $pdo->prepare(
                'INSERT INTO debt_payments (debt_id, user_id, wallet_id, amount, note, payment_date, is_settlement)
                 VALUES (:d, :u, :w, :a, :n, :dt, 1)'
            )->execute([
                'd' => $debtId, 'u' => $userId, 'w' => $walletId, 'a' => $remaining,
                'n' => 'تسویه کامل', 'dt' => today(),
            ]);
            $moved = $remaining;

            if ($walletId !== null) {
                $wn = $pdo->prepare('SELECT name FROM wallets WHERE id = :id AND user_id = :u');
                $wn->execute(['id' => $walletId, 'u' => $userId]);
                $walletName = (string)$wn->fetchColumn();
            }
        }

        $pdo->prepare('UPDATE debts SET paid_amount = amount, is_settled = 1, settled_at = :sa WHERE id = :id AND user_id = :u')
            ->execute(['sa' => date('Y-m-d H:i:s'), 'id' => $debtId, 'u' => $userId]);
    } else {
        // برداشتن تیک: فقط پرداختی که خودِ تسویه ساخته بود پس گرفته می‌شود
        $pdo->prepare('DELETE FROM debt_payments WHERE debt_id = :d AND user_id = :u AND is_settlement = 1')
            ->execute(['d' => $debtId, 'u' => $userId]);

        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM debt_payments WHERE debt_id = :d AND user_id = :u');
        $sum->execute(['d' => $debtId, 'u' => $userId]);
        $paid = (int)$sum->fetchColumn();

        $pdo->prepare('UPDATE debts SET paid_amount = :p, is_settled = 0, settled_at = NULL WHERE id = :id AND user_id = :u')
            ->execute(['p' => $paid, 'id' => $debtId, 'u' => $userId]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Toggle Debt Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}

if ($newStatus === 1) {
    $verb = $debt['direction'] === 'receivable' ? 'به' : 'از';
    $message = ($walletName !== '' && $moved > 0)
        ? 'تسویه شد — ' . formatMoney($moved) . " تومان {$verb} «{$walletName}» اعمال شد."
        : 'به‌عنوان تسویه‌شده علامت خورد.';
} else {
    $message = 'علامت تسویه برداشته شد و اثرش روی موجودی حساب پس گرفته شد.';
}

jsonResponse([
    'success'    => true,
    'is_settled' => $newStatus,
    'message'    => $message,
]);
