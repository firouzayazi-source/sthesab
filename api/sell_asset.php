<?php
/**
 * فروش یک دارایی از بخش معاملات.
 *
 * دارایی و کالای معاملاتی هر دو «چیزی که داریم»‌اند، ولی دو جور ثبت
 * می‌شوند. این اندپوینت پل میانشان است: مقدار فروخته‌شده از دارایی کم
 * می‌شود و همان مقدار به‌صورت یک معامله‌ی بسته‌شده در سوابق می‌نشیند.
 *
 * بهای خرید از «قیمت واحدِ» ثبت‌شده‌ی همان دارایی می‌آید، پس سود درست
 * حساب می‌شود و مثل هر فروش دیگری وارد حسابداری می‌شود.
 *
 * معامله‌ی ساخته‌شده عمداً buy_wallet_id ندارد: پولِ خرید قبلاً و جای
 * دیگری خرج شده؛ اگر اینجا حساب می‌خورد، موجودی اشتباه کم می‌شد.
 */
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

$assetId   = (int)postParam('asset_id');
$qty       = sanitizeQty(postParam('qty', '1'));
$saleTotal = sanitizeAmount(postParam('sale_total'));
$saleDate  = postParam('sale_date');
$walletId  = (int)postParam('wallet_id');
$notes     = trim(postParam('notes'));

$errors = [];
if ($qty <= 0) { $errors[] = 'مقدار فروش باید بزرگ‌تر از صفر باشد.'; }
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

$tradeId = 0;
try {
    $pdo->beginTransaction();

    // قفل روی ردیف دارایی تا دو فروش هم‌زمان بیشتر از موجودی نفروشند
    $st = $pdo->prepare(
        'SELECT a.id, a.quantity, a.unit_price, a.entry_date, at.name AS type_name
         FROM assets a
         JOIN asset_types at ON at.id = a.asset_type_id
         WHERE a.id = :id AND a.user_id = :u
         FOR UPDATE'
    );
    $st->execute(['id' => $assetId, 'u' => $userId]);
    $asset = $st->fetch();
    if (!$asset) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'دارایی یافت نشد.'], 404);
    }

    $available = round((float)$asset['quantity'], 3);
    if ($qty > $available + 0.0005) {
        $pdo->rollBack();
        jsonResponse(['success' => false,
            'message' => 'بیشتر از موجودی است — ' . formatQty($available) . ' واحد دارید.'], 422);
    }

    // بهای خرید سهم فروخته‌شده
    $unitPrice = (int)($asset['unit_price'] ?? 0);
    $buyTotal  = (int)round($qty * $unitPrice);

    $ins = $pdo->prepare(
        'INSERT INTO trades (user_id, title, qty, buy_total, side_costs, buy_date, buy_wallet_id, notes)
         VALUES (:u, :t, :q, :b, 0, :d, NULL, :n)'
    );
    $ins->execute([
        'u' => $userId,
        't' => mb_substr($asset['type_name'], 0, 150),
        'q' => $qty,
        'b' => $buyTotal,
        'd' => $asset['entry_date'],
        'n' => 'فروش از بخش دارایی‌ها',
    ]);
    $tradeId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO trade_sales (trade_id, user_id, qty, sale_total, sale_date, wallet_id, notes)
         VALUES (:t, :u, :q, :s, :d, :w, :n)'
    )->execute([
        't' => $tradeId, 'u' => $userId, 'q' => $qty, 's' => $saleTotal,
        'd' => $saleDate, 'w' => $walletId > 0 ? $walletId : null,
        'n' => $notes !== '' ? $notes : null,
    ]);

    // مقدار از دارایی کم می‌شود؛ اگر چیزی نماند، ردیف برداشته می‌شود
    $left = round($available - $qty, 3);
    if ($left <= 0.0005) {
        $pdo->prepare('DELETE FROM assets WHERE id = :id AND user_id = :u')
            ->execute(['id' => $assetId, 'u' => $userId]);
    } else {
        $pdo->prepare('UPDATE assets SET quantity = :q WHERE id = :id AND user_id = :u')
            ->execute(['q' => $left, 'id' => $assetId, 'u' => $userId]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Sell Asset Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}

// سود این فروش هم مثل بقیه وارد حسابداری می‌شود
syncTradeProfitTransactions($userId, $tradeId);

jsonResponse(['success' => true, 'message' => 'فروش ثبت شد و از دارایی‌ها کم شد.']);
