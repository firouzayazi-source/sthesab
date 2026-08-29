<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

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

$errors = [];
if ($title === '' || mb_strlen($title) > 150) { $errors[] = 'عنوان معامله الزامی است.'; }
if ($qty <= 0) { $errors[] = 'تعداد باید بزرگ‌تر از صفر باشد.'; }
if ($qty > 999999) { $errors[] = 'تعداد بیش از حد بزرگ است.'; }
if ($buyTotal <= 0) { $errors[] = 'مبلغ خرید الزامی است.'; }
if ($buyTotal > 999999999999 || $sideCosts > 999999999999) { $errors[] = 'مبلغ بیش از حد بزرگ است.'; }
if (!isValidDate($buyDate)) { $errors[] = 'تاریخ خرید نامعتبر است.'; }
if (mb_strlen($notes) > 2000) { $errors[] = 'توضیحات بیش از حد بلند است.'; }

// حساب انتخاب‌شده باید مال خود کاربر باشد
if ($walletId > 0) {
    $own = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
    $own->execute(['id' => $walletId, 'u' => $userId]);
    if (!$own->fetch()) { $errors[] = 'حساب انتخاب‌شده معتبر نیست.'; }
}

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

try {
    if ($tradeId > 0) {
        $own = $pdo->prepare('SELECT id, qty FROM trades WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $tradeId, 'u' => $userId]);
        $existing = $own->fetch();
        if (!$existing) { jsonResponse(['success' => false, 'message' => 'معامله یافت نشد.'], 404); }

        // تعداد را نمی‌شود کمتر از مقدارِ تاکنون فروخته‌شده کرد
        $sold = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM trade_sales WHERE trade_id = :t AND user_id = :u');
        $sold->execute(['t' => $tradeId, 'u' => $userId]);
        $soldQty = (float)$sold->fetchColumn();
        if ($qty < $soldQty) {
            jsonResponse(['success' => false,
                'message' => 'تعداد نمی‌تواند کمتر از مقدار فروخته‌شده (' . toPersianDigits(rtrim(rtrim(number_format($soldQty, 3, '.', ''), '0'), '.')) . ') باشد.'], 422);
        }

        $st = $pdo->prepare(
            'UPDATE trades SET title = :t, qty = :q, buy_total = :b, side_costs = :s,
                    buy_date = :d, buy_wallet_id = :w, notes = :n
             WHERE id = :id AND user_id = :u'
        );
        $st->execute([
            't' => $title, 'q' => $qty, 'b' => $buyTotal, 's' => $sideCosts,
            'd' => $buyDate, 'w' => $walletId > 0 ? $walletId : null,
            'n' => $notes !== '' ? $notes : null,
            'id' => $tradeId, 'u' => $userId,
        ]);
        jsonResponse(['success' => true, 'message' => 'معامله بروزرسانی شد.']);
    }

    $st = $pdo->prepare(
        'INSERT INTO trades (user_id, title, qty, buy_total, side_costs, buy_date, buy_wallet_id, notes)
         VALUES (:u, :t, :q, :b, :s, :d, :w, :n)'
    );
    $st->execute([
        'u' => $userId, 't' => $title, 'q' => $qty, 'b' => $buyTotal, 's' => $sideCosts,
        'd' => $buyDate, 'w' => $walletId > 0 ? $walletId : null,
        'n' => $notes !== '' ? $notes : null,
    ]);
    jsonResponse(['success' => true, 'message' => 'خرید ثبت شد.']);
} catch (PDOException $e) {
    error_log('Save Trade Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
