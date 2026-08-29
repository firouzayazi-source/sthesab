<?php
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
$walletId = (int)postParam('wallet_id');
$name     = postParam('name');
$kind     = postParam('kind', 'cash');
$bankName = postParam('bank_name');
$last4    = toLatinDigits(postParam('card_last4'));
$color    = postParam('color', '#64748b');
$rawInit  = postParam('initial_balance');

// نوع دلخواه — فقط وقتی kind = 'other' است معنا دارد. اگر کاربر نوع
// تازه‌ای نوشته باشد، برای دفعات بعد در فهرست خودش ذخیره می‌شود؛ همان
// رفتاری که برای نام بانک‌ها هست.
$kindLabel = trim(postParam('kind_label'));
$kindNew   = trim(postParam('kind_label_new'));
if ($kindLabel === '__new__' || $kindNew !== '') { $kindLabel = $kindNew; }
if (mb_strlen($kindLabel) > 60) { $kindLabel = mb_substr($kindLabel, 0, 60); }

// اطلاعات کارت — با migration_wallet_cards آمده‌اند. روی نصبی که هنوز
// اجرا نشده، بقیه‌ی فرم باید مثل قبل کار کند، پس شرطی نوشته شده‌اند.
$hasCardCols = tableHasColumn('wallets', 'card_number');

$bankCode = postParam('bank_code');
$cardNum  = digitsOnly(postParam('card_number'), 19);
$accountNo= digitsOnly(postParam('account_number'), 30);
$iban     = digitsOnly(postParam('iban'), 24);

// اگر بانک از فهرست انتخاب شده ولی نام دلخواه خالی مانده، نام بانک بنشیند
if ($bankCode !== '' && $bankName === '') {
    $preset = bankPreset($bankCode);
    if ($preset) { $bankName = $preset['name']; }
}
// شماره کارت که کامل باشد، ۴ رقم آخر را خودش می‌دهد
if ($last4 === '' && mb_strlen($cardNum) >= 4) {
    $last4 = mb_substr($cardNum, -4);
}

$errors = [];

if ($name === '' || mb_strlen($name) > 100) {
    $errors[] = 'نام حساب الزامی است و باید کمتر از ۱۰۰ کاراکتر باشد.';
}
if (!in_array($kind, ['cash', 'bank', 'card', 'other'], true)) {
    $kind = 'cash';
}
if ($kind !== 'other') { $kindLabel = ''; }
if ($last4 !== '' && !preg_match('/^[0-9]{4}$/', $last4)) {
    $errors[] = 'چهار رقم آخر کارت باید دقیقاً ۴ رقم باشد.';
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    $color = '#64748b';
}
if (mb_strlen($bankName) > 100) {
    $errors[] = 'نام بانک بیش از حد طولانی است.';
}
if ($bankCode !== '' && bankPreset($bankCode) === null) {
    $errors[] = 'بانک انتخاب‌شده معتبر نیست.';
}
// کارت‌های ایرانی ۱۶ رقمی‌اند و شبا ۲۴ رقم (بدون IR). خالی هم مجاز است.
if ($cardNum !== '' && !preg_match('/^[0-9]{16}$/', $cardNum)) {
    $errors[] = 'شماره کارت باید دقیقاً ۱۶ رقم باشد.';
}
if ($iban !== '' && !preg_match('/^[0-9]{24}$/', $iban)) {
    $errors[] = 'شبا باید ۲۴ رقم بعد از IR باشد.';
}
if ($accountNo !== '' && mb_strlen($accountNo) < 4) {
    $errors[] = 'شماره حساب کوتاه‌تر از حد انتظار است.';
}

$initial = sanitizeAmount($rawInit);
if ($initial > 999999999999) {
    $errors[] = 'موجودی اولیه بیش از حد بزرگ است.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

/**
 * ثبت نوع دلخواه روی حساب، و افزودنش به فهرست کاربر برای دفعات بعد.
 *
 * جدا از کوئری اصلی نوشته شده تا آن SQL شاخه‌شاخه نشود؛ ستون و جدولش
 * با migration_wallet_kinds می‌آیند و روی نصبی که هنوز اجرا نشده، این
 * تابع بی‌سروصدا کاری نمی‌کند.
 */
function saveWalletKind(PDO $pdo, int $userId, int $walletId, string $kind, string $label): void
{
    if ($walletId <= 0 || !tableHasColumn('wallets', 'kind_label')) { return; }

    $label = $kind === 'other' ? trim($label) : '';

    $pdo->prepare('UPDATE wallets SET kind_label = :l WHERE id = :id AND user_id = :u')
        ->execute(['l' => $label !== '' ? $label : null, 'id' => $walletId, 'u' => $userId]);

    if ($label !== '' && tableExists('wallet_kinds')) {
        $pdo->prepare('INSERT IGNORE INTO wallet_kinds (user_id, name) VALUES (:u, :n)')
            ->execute(['u' => $userId, 'n' => $label]);
    }
}

$pdo = Database::getConnection();

try {
    if ($walletId > 0) {
        $own = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $walletId, 'u' => $userId]);
        if (!$own->fetch()) {
            jsonResponse(['success' => false, 'message' => 'حساب یافت نشد.'], 404);
        }

        $sql = $hasCardCols
            ? 'UPDATE wallets
               SET name = :name, kind = :kind, bank_name = :bank_name,
                   card_last4 = :last4, color = :color, initial_balance = :init,
                   bank_code = :bcode, card_number = :cardno,
                   account_number = :accno, iban = :iban
               WHERE id = :id AND user_id = :u'
            : 'UPDATE wallets
               SET name = :name, kind = :kind, bank_name = :bank_name,
                   card_last4 = :last4, color = :color, initial_balance = :init
               WHERE id = :id AND user_id = :u';
        $stmt = $pdo->prepare($sql);
        $params = [
            'name' => $name, 'kind' => $kind,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'last4' => $last4 !== '' ? $last4 : null,
            'color' => $color, 'init' => $initial,
            'id' => $walletId, 'u' => $userId,
        ];
        if ($hasCardCols) {
            $params += [
                'bcode'  => $bankCode !== ''  ? $bankCode  : null,
                'cardno' => $cardNum !== ''   ? $cardNum   : null,
                'accno'  => $accountNo !== '' ? $accountNo : null,
                'iban'   => $iban !== ''      ? $iban      : null,
            ];
        }
        $stmt->execute($params);
        saveWalletKind($pdo, $userId, $walletId, $kind, $kindLabel);

        jsonResponse(['success' => true, 'message' => 'حساب بروزرسانی شد.']);
    }

    // دو کوئری ثابت به‌جای ساختن رشته‌ی SQL با متغیر — همان قاعده‌ای که
    // در CLAUDE.md آمده و تست قرارداد نگهش می‌دارد.
    $sql = $hasCardCols
        ? 'INSERT INTO wallets (user_id, name, kind, bank_name, card_last4, color, initial_balance,
                                bank_code, card_number, account_number, iban)
           VALUES (:u, :name, :kind, :bank_name, :last4, :color, :init,
                   :bcode, :cardno, :accno, :iban)'
        : 'INSERT INTO wallets (user_id, name, kind, bank_name, card_last4, color, initial_balance)
           VALUES (:u, :name, :kind, :bank_name, :last4, :color, :init)';
    $stmt = $pdo->prepare($sql);
    $params = [
        'u' => $userId, 'name' => $name, 'kind' => $kind,
        'bank_name' => $bankName !== '' ? $bankName : null,
        'last4' => $last4 !== '' ? $last4 : null,
        'color' => $color, 'init' => $initial,
    ];
    if ($hasCardCols) {
        $params += [
            'bcode'  => $bankCode !== ''  ? $bankCode  : null,
            'cardno' => $cardNum !== ''   ? $cardNum   : null,
            'accno'  => $accountNo !== '' ? $accountNo : null,
            'iban'   => $iban !== ''      ? $iban      : null,
        ];
    }
    $stmt->execute($params);
    saveWalletKind($pdo, $userId, (int)$pdo->lastInsertId(), $kind, $kindLabel);

    jsonResponse(['success' => true, 'message' => 'حساب ساخته شد.']);
} catch (PDOException $e) {
    error_log('Save Wallet Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ذخیره حساب رخ داد.'], 500);
}
