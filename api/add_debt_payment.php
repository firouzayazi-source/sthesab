<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ اینجا عمداً `apiRequirePlan()` نیست: این اندپوینت رکوردِ تازه
//    نمی‌سازد، فقط چیزی را که کاربر از قبل ثبت کرده ویرایش/تسویه/حذف
//    می‌کند. بستنش یعنی دفترِ کاربر روی واقعیتِ ماه‌ها پیش یخ می‌زند —
//    و دفترِ غلط از دفترِ نداشته بدتر است. قاعده ۲۶ در
//    `test_api_contract.php` این فهرست را بسته نگه می‌دارد.

$userId = Auth::userId();
$debtId = (int)postParam('debt_id');
$amount = sanitizeAmount(postParam('amount'));
$note   = postParam('note');
$date   = postParam('payment_date');

$errors = [];
if ($amount <= 0) { $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.'; }
if (!isValidDate($date)) { $errors[] = 'تاریخ نامعتبر است.'; }
if (mb_strlen($note) > 1000) { $errors[] = 'توضیحات بیش از حد طولانی است.'; }

$pdo = Database::getConnection();
$debtStmt = $pdo->prepare('SELECT id, amount, paid_amount, is_settled FROM debts WHERE id = :id AND user_id = :u');
$debtStmt->execute(['id' => $debtId, 'u' => $userId]);
$debt = $debtStmt->fetch();

if (!$debt) { jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404); }
if ((int)$debt['is_settled']) { jsonResponse(['success' => false, 'message' => 'این مورد قبلاً تسویه شده است.'], 422); }

$remaining = (int)$debt['amount'] - (int)$debt['paid_amount'];
if ($amount > $remaining) {
    $errors[] = 'مبلغ پرداخت از باقیمانده (' . formatMoney($remaining) . ' تومان) بیشتر است.';
}

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

try {
    $pdo->beginTransaction();

    // حسابِ پرداخت هم ثبت می‌شود تا موجودی همان حساب واقعاً کم/زیاد شود
    // (walletBalances از همین جدول می‌خواند). ستون با migration_money_links
    // می‌آید؛ بدون آن، پرداخت مثل قبل فقط ثبت می‌شود.
    if (tableHasColumn('debt_payments', 'wallet_id')) {
        $ins = $pdo->prepare(
            'INSERT INTO debt_payments (debt_id, user_id, wallet_id, amount, note, payment_date)
             VALUES (:d, :u, :w, :a, :n, :dt)'
        );
        $ins->execute([
            'd' => $debtId, 'u' => $userId, 'w' => resolveWalletId($userId, postParam('wallet_id')),
            'a' => $amount, 'n' => $note !== '' ? $note : null, 'dt' => $date,
        ]);
    } else {
        $ins = $pdo->prepare('INSERT INTO debt_payments (debt_id, user_id, amount, note, payment_date) VALUES (:d, :u, :a, :n, :dt)');
        $ins->execute(['d' => $debtId, 'u' => $userId, 'a' => $amount, 'n' => $note !== '' ? $note : null, 'dt' => $date]);
    }

    $newPaid = (int)$debt['paid_amount'] + $amount;
    $nowSettled = $newPaid >= (int)$debt['amount'];

    $upd = $pdo->prepare('
        UPDATE debts SET paid_amount = :p, is_settled = :s, settled_at = :sa
        WHERE id = :id AND user_id = :u
    ');
    $upd->execute([
        'p' => $newPaid,
        's' => $nowSettled ? 1 : 0,
        'sa' => $nowSettled ? date('Y-m-d H:i:s') : null,
        'id' => $debtId, 'u' => $userId,
    ]);

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'fully_settled' => $nowSettled,
        'message' => $nowSettled ? 'پرداخت ثبت شد و کامل تسویه شد.' : 'پرداخت ثبت شد.',
    ]);
} catch (PDOException $e) {
    $pdo->rollBack();
    Log::error('api.add_debt_payment', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
