<?php
/**
 * تعدیل موجودی یک حساب — کم و زیاد کردن دستی.
 *
 * چرا لازم است: موجودی هر حساب یک عدد محاسبه‌شده است (موجودی اولیه به‌علاوه
 * درآمدها منهای هزینه‌ها و ...). وقتی اپ را وسط کار راه می‌اندازید یا چند
 * تراکنش قدیمی ثبت نشده، عدد محاسبه‌شده با پول واقعی جیب/حساب نمی‌خواند و
 * حتی منفی می‌شود.
 *
 * کار استاندارد در حسابداری همین است: «مغایرت‌گیری» — عدد را با واقعیت
 * برابر می‌کنی و تفاوت را به‌عنوان تعدیل ثبت می‌کنی.
 *
 * اینجا تعدیل روی `initial_balance` می‌نشیند، نه به شکل یک تراکنش. عمدی است:
 * یک تعدیل نه درآمد است نه هزینه، و اگر تراکنش می‌شد گزارش درآمد/هزینه‌ی
 * همان ماه را الکی بالا می‌برد. با تغییر موجودی اولیه، هیچ گزارشی جابه‌جا
 * نمی‌شود و فقط مبدأ حساب درست می‌شود.
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
$mode     = postParam('mode', 'add');
$amount   = sanitizeAmount(postParam('amount'));

if (!in_array($mode, ['add', 'sub', 'set'], true)) {
    jsonResponse(['success' => false, 'message' => 'نوع تعدیل نامعتبر است.'], 422);
}
if ($amount <= 0 && $mode !== 'set') {
    jsonResponse(['success' => false, 'message' => 'مبلغ باید بزرگ‌تر از صفر باشد.'], 422);
}
if ($amount > 999999999999) {
    jsonResponse(['success' => false, 'message' => 'مبلغ بیش از حد بزرگ است.'], 422);
}

$negative = postParam('negative') === '1';   // فقط برای حالت «موجودی واقعی»
if ($mode === 'set' && $negative) { $amount = -$amount; }

$pdo = Database::getConnection();

$own = $pdo->prepare('SELECT id, name, initial_balance FROM wallets WHERE id = :id AND user_id = :u');
$own->execute(['id' => $walletId, 'u' => $userId]);
$wallet = $own->fetch();
if (!$wallet) { jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404); }

// موجودی فعلیِ محاسبه‌شده — برای حالت «موجودی واقعی این‌قدر است»
$current = null;
foreach (walletBalances($userId) as $w) {
    if ((int)$w['id'] === $walletId) { $current = (int)$w['balance']; break; }
}
if ($current === null) { jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404); }

if ($mode === 'add') {
    $delta = $amount;
} elseif ($mode === 'sub') {
    $delta = -$amount;
} else {
    $delta = $amount - $current;   // تفاوت تا رسیدن به عدد واقعی
}

// ⛔ حتی وقتی هیچ عددی عوض نمی‌شود، کاربر **پاسخ داده** است. بدونِ این
//    خط، کسی که موجودی واقعی‌اش دقیقاً همان عددِ فعلی است، کارتِ
//    «دقیقه‌ی اول» را تا ابد می‌دید — یعنی کاری که انجام داده نادیده
//    گرفته می‌شد.
markBalanceSetup($userId);

if ($delta === 0) {
    jsonResponse(['success' => true, 'balance' => $current, 'message' => 'موجودی از قبل همین بود — چیزی تغییر نکرد.']);
}

$newInitial = (int)$wallet['initial_balance'] + $delta;

try {
    $pdo->prepare('UPDATE wallets SET initial_balance = :b WHERE id = :id AND user_id = :u')
        ->execute(['b' => $newInitial, 'id' => $walletId, 'u' => $userId]);
} catch (PDOException $e) {
    Log::error('api.adjust_wallet', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}

$newBalance = $current + $delta;

jsonResponse([
    'success' => true,
    'balance' => $newBalance,
    'message' => ($delta > 0 ? formatMoney($delta) . ' تومان اضافه شد.' : formatMoney(abs($delta)) . ' تومان کم شد.')
        . ' موجودی جدید: ' . ($newBalance < 0 ? '−' : '') . formatMoney(abs($newBalance)) . ' تومان.',
]);
