<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/trade_credit.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ قفلِ صفحه بدونِ این فقط تزئین است: کسی که آدرسِ اندپوینت را
//    بداند مستقیم صدایش می‌زند.
require_once __DIR__ . '/../includes/plan_gate.php';
apiRequirePlan('trades');

$userId = Auth::userId();
$pdo = Database::getConnection();

if (!tradesTablesExist($pdo)) {
    jsonResponse(['success' => false, 'message' => 'جدول معاملات هنوز ساخته نشده. روی سرور:  bash deploy/migrate.sh --apply'], 500);
}

$tradeId   = (int)postParam('trade_id');
$title     = trim(postParam('title'));
$qty       = sanitizeQty(postParam('qty', '1'));
$buyTotal  = sanitizeAmount(postParam('buy_total'));
$sideCosts = sanitizeAmount(postParam('side_costs', '0'));
$buyDate   = postParam('buy_date');
$walletId  = (int)postParam('buy_wallet_id');
$notes     = trim(postParam('notes'));

// نام طرف مقابل (فروشنده). ستون ممکن است هنوز با migration نیامده باشد،
// پس پایین‌تر مشروط به وجودش نوشته می‌شود.
$counterparty = trim(postParam('counterparty_name'));
$hasCp = tableHasColumn('trades', 'counterparty_name');

// ⛔ امانی/نسیه: پولی از هیچ حسابی کم نمی‌شود و به‌جایش یک **بدهی** به
//    فروشنده در «طلب و بدهی» ساخته می‌شود (includes/trade_credit.php).
//    حالتِ صریح است نه حدس از «حسابِ خالی» — «بدون برداشت از حساب»
//    گزینه‌ی دیگری است.
$hasCredit = tradeCreditAvailable();
$onCredit  = $hasCredit && postParam('pay_mode') === 'credit';
if ($onCredit) { $walletId = 0; }

$errors = [];
if ($title === '' || mb_strlen($title) > 150) { $errors[] = 'عنوان معامله الزامی است.'; }
if ($qty <= 0) { $errors[] = 'تعداد باید بزرگ‌تر از صفر باشد.'; }
if ($qty > 999999) { $errors[] = 'تعداد بیش از حد بزرگ است.'; }
if ($buyTotal <= 0) { $errors[] = 'مبلغ خرید الزامی است.'; }
if ($buyTotal > 999999999999 || $sideCosts > 999999999999) { $errors[] = 'مبلغ بیش از حد بزرگ است.'; }
if (!isValidDate($buyDate)) { $errors[] = 'تاریخ خرید نامعتبر است.'; }
if (mb_strlen($notes) > 2000) { $errors[] = 'توضیحات بیش از حد بلند است.'; }
if ($onCredit && $counterparty === '') { $errors[] = 'برای خرید امانی/نسیه، نام فروشنده لازم است.'; }

// حساب انتخاب‌شده باید مال خود کاربر باشد
if ($walletId > 0) {
    $own = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
    $own->execute(['id' => $walletId, 'u' => $userId]);
    if (!$own->fetch()) { $errors[] = 'حساب انتخاب‌شده معتبر نیست.'; }
}

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$creditNote = 'امانی/نسیه — خرید «' . $title . '» در معاملات';

try {
    $pdo->beginTransaction();
    if ($tradeId > 0) {
        $own = $pdo->prepare('SELECT id, qty FROM trades WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $tradeId, 'u' => $userId]);
        $existing = $own->fetch();
        if (!$existing) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => 'معامله یافت نشد.'], 404); }

        // تعداد را نمی‌شود کمتر از مقدارِ تاکنون فروخته‌شده کرد
        $sold = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM trade_sales WHERE trade_id = :t AND user_id = :u');
        $sold->execute(['t' => $tradeId, 'u' => $userId]);
        $soldQty = (float)$sold->fetchColumn();
        if ($qty < $soldQty) {
            $pdo->rollBack();
            jsonResponse(['success' => false,
                'message' => 'تعداد نمی‌تواند کمتر از مقدار فروخته‌شده (' . toPersianDigits(rtrim(rtrim(number_format($soldQty, 3, '.', ''), '0'), '.')) . ') باشد.'], 422);
        }

        $st = $pdo->prepare(
            'UPDATE trades SET title = :t, qty = :q, buy_total = :b, side_costs = :s,
                    buy_date = :d, buy_wallet_id = :w, notes = :n'
             . ($hasCp ? ', counterparty_name = :cp' : '')
             . ($hasCredit ? ', on_credit = :oc' : '') .
            ' WHERE id = :id AND user_id = :u'
        );
        $st->execute([
            't' => $title, 'q' => $qty, 'b' => $buyTotal, 's' => $sideCosts,
            'd' => $buyDate, 'w' => $walletId > 0 ? $walletId : null,
            'n' => $notes !== '' ? $notes : null,
            'id' => $tradeId, 'u' => $userId,
        ] + ($hasCp ? ['cp' => $counterparty !== '' ? $counterparty : null] : [])
          + ($hasCredit ? ['oc' => $onCredit ? 1 : 0] : []));

        // بدهیِ پیوندی با خودِ معامله هم‌گام می‌ماند: نسیه → ساخته/به‌روز؛
        // نقدی شد → پس گرفته می‌شود، مگر پرداخت داشته باشد.
        $kept = 0;
        if ($onCredit) {
            $r = tradeCreditUpsert($pdo, $userId, 'buy', $tradeId, $counterparty, $buyTotal, $buyDate, $creditNote);
            if (!$r['ok']) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => $r['error']], 422); }
        } elseif ($hasCredit) {
            $kept = tradeCreditRelease($pdo, $userId, 'buy', $tradeId) === 'kept' ? 1 : 0;
        }
        $pdo->commit();
        // تغییر مبلغ/تعداد/هزینه‌ی جانبی، سودِ فروش‌های قبلی را عوض می‌کند
        syncTradeProfitTransactions($userId, $tradeId);
        jsonResponse(['success' => true, 'message' => 'معامله بروزرسانی شد.' . tradeCreditKeptNote($kept)]);
    }

    $st = $pdo->prepare(
        'INSERT INTO trades (user_id, title, qty, buy_total, side_costs, buy_date, buy_wallet_id, notes'
        . ($hasCp ? ', counterparty_name' : '')
        . ($hasCredit ? ', on_credit' : '') .
        ') VALUES (:u, :t, :q, :b, :s, :d, :w, :n'
        . ($hasCp ? ', :cp' : '')
        . ($hasCredit ? ', :oc' : '') . ')'
    );
    $st->execute([
        'u' => $userId, 't' => $title, 'q' => $qty, 'b' => $buyTotal, 's' => $sideCosts,
        'd' => $buyDate, 'w' => $walletId > 0 ? $walletId : null,
        'n' => $notes !== '' ? $notes : null,
    ] + ($hasCp ? ['cp' => $counterparty !== '' ? $counterparty : null] : [])
      + ($hasCredit ? ['oc' => $onCredit ? 1 : 0] : []));
    $newId = (int)$pdo->lastInsertId();

    if ($onCredit) {
        $r = tradeCreditUpsert($pdo, $userId, 'buy', $newId, $counterparty, $buyTotal, $buyDate, $creditNote);
        if (!$r['ok']) { $pdo->rollBack(); jsonResponse(['success' => false, 'message' => $r['error']], 422); }
    }
    $pdo->commit();
    jsonResponse(['success' => true, 'message' => $onCredit
        ? "خرید ثبت شد و بدهی به «{$counterparty}» در طلب و بدهی نشست."
        : 'خرید ثبت شد.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    Log::error('api.save_trade', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
