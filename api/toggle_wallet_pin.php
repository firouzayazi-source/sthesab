<?php
/**
 * پین/برداشتنِ یک حساب از صفحه‌ی خانه.
 *
 * ⚠ عمداً یک اندپوینتِ کوچکِ جداست، نه یک فیلد در `save_wallet.php`:
 *   آن فرم کلِ حساب را می‌نویسد (نام، رنگ، شماره‌ی کارتِ رمزشده، …) و
 *   یک تپِ ساده روی آیکونِ پین نباید کلِ آن مسیر را اجرا کند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId   = Auth::userId();
$walletId = (int)postParam('wallet_id');
$pin      = postParam('pinned') === '1';

if (!tableHasColumn('wallets', 'pinned')) {
    jsonResponse(['success' => false, 'message' => 'این قابلیت هنوز روی سرور اجرا نشده است.'], 400);
}

$pdo = Database::getConnection();

// ⛔ جداسازی کاربران: شناسه از ورودی می‌آید، پس مالکیت باید سنجیده شود.
$own = $pdo->prepare('SELECT id, is_active FROM wallets WHERE id = :id AND user_id = :u');
$own->execute(['id' => $walletId, 'u' => $userId]);
$wallet = $own->fetch();
if (!$wallet) { jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404); }

// ⛔ سقف **اینجا** هم سنجیده می‌شود، نه فقط در `pinnedWallets()`.
//    آن یکی هنگام *نمایش* می‌بُرد؛ اگر فقط همان بود، کاربر ۱۵ حساب پین
//    می‌کرد، ۸ تا می‌دید، و نمی‌فهمید بقیه کجا رفتند — یعنی کلیدی که
//    بی‌صدا کار نمی‌کند.
if ($pin) {
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM wallets
                          WHERE user_id = :u AND pinned = 1 AND is_active = 1 AND id <> :id');
    $cnt->execute(['u' => $userId, 'id' => $walletId]);
    if ((int)$cnt->fetchColumn() >= PINNED_WALLET_MAX) {
        jsonResponse([
            'success' => false,
            'message' => 'حداکثر ' . toPersianDigits(PINNED_WALLET_MAX)
                . ' حساب روی صفحه‌ی خانه جا می‌شود. اول یکی را بردارید.',
        ], 422);
    }
}

try {
    $pdo->prepare('UPDATE wallets SET pinned = :p WHERE id = :id AND user_id = :u')
        ->execute(['p' => $pin ? 1 : 0, 'id' => $walletId, 'u' => $userId]);
} catch (PDOException $e) {
    Log::error('api.toggle_wallet_pin', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}

jsonResponse([
    'success' => true,
    'pinned'  => $pin,
    'message' => $pin ? 'به صفحه‌ی خانه اضافه شد.' : 'از صفحه‌ی خانه برداشته شد.',
]);
