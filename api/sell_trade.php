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
$pdo = Database::getConnection();

$tradeId   = (int)postParam('trade_id');
$qty       = sanitizeQty(postParam('qty', '1'));
$saleTotal = sanitizeAmount(postParam('sale_total'));
$saleDate  = postParam('sale_date');
$walletId  = (int)postParam('wallet_id');
$notes     = trim(postParam('notes'));

// نام خریدار. ستون ممکن است هنوز با migration نیامده باشد.
$counterparty = trim(postParam('counterparty_name'));
$hasCp = tableHasColumn('trade_sales', 'counterparty_name');

$errors = [];
if ($qty <= 0) { $errors[] = 'تعداد فروش باید بزرگ‌تر از صفر باشد.'; }
if ($saleTotal <= 0) { $errors[] = 'مبلغ فروش الزامی است.'; }
if ($saleTotal > 999999999999) { $errors[] = 'مبلغ بیش از حد بزرگ است.'; }
if (!isValidDate($saleDate)) { $errors[] = 'تاریخ فروش نامعتبر است.'; }
if (mb_strlen($notes) > 500) { $errors[] = 'توضیحات بیش از حد بلند است.'; }

if ($walletId > 0) {
    $own = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
    $own->execute(['id' => $walletId, 'u' => $userId]);
    if (!$own->fetch()) { $errors[] = 'حساب انتخاب‌شده معتبر نیست.'; }
}

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

try {
    // قفل روی معامله تا دو فروش هم‌زمان نتوانند بیشتر از موجودی بفروشند
    $pdo->beginTransaction();

    $st = $pdo->prepare('SELECT qty, buy_date FROM trades WHERE id = :id AND user_id = :u FOR UPDATE');
    $st->execute(['id' => $tradeId, 'u' => $userId]);
    $trade = $st->fetch();
    if (!$trade) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'معامله یافت نشد.'], 404);
    }

    if ($saleDate < $trade['buy_date']) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'تاریخ فروش نمی‌تواند قبل از تاریخ خرید باشد.'], 422);
    }

    $sold = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM trade_sales WHERE trade_id = :t AND user_id = :u');
    $sold->execute(['t' => $tradeId, 'u' => $userId]);
    $remaining = round((float)$trade['qty'] - (float)$sold->fetchColumn(), 3);

    if ($qty > $remaining + 0.0005) {
        $pdo->rollBack();
        jsonResponse(['success' => false,
            'message' => 'بیشتر از موجودی است — از این معامله ' .
                toPersianDigits(rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.')) . ' واحد مانده.'], 422);
    }

    $ins = $pdo->prepare(
        'INSERT INTO trade_sales (trade_id, user_id, qty, sale_total, sale_date, wallet_id, notes'
        . ($hasCp ? ', counterparty_name' : '') .
        ') VALUES (:t, :u, :q, :s, :d, :w, :n'
        . ($hasCp ? ', :cp' : '') . ')'
    );
    $ins->execute([
        't' => $tradeId, 'u' => $userId, 'q' => $qty, 's' => $saleTotal,
        'd' => $saleDate, 'w' => resolveWalletId($userId, $walletId),
        'n' => $notes !== '' ? $notes : null,
    ] + ($hasCp ? ['cp' => $counterparty !== '' ? $counterparty : null] : []));

    $pdo->commit();

    // سهم سود این فروش به‌صورت تراکنش در حسابداری ثبت می‌شود
    syncTradeProfitTransactions($userId, $tradeId);

    jsonResponse(['success' => true, 'message' => 'فروش ثبت شد.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Log::error('api.sell_trade', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
