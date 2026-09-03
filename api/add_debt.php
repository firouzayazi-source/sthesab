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

$direction = postParam('direction');
$counterpartyName = postParam('counterparty_name');
$rawAmount = postParam('amount');
$note = postParam('note');
$entryDate = postParam('entry_date');
$dueDate = postParam('due_date');

$errors = [];

if (!in_array($direction, ['receivable', 'payable'], true)) {
    $errors[] = 'نوع نامعتبر است.';
}

if ($counterpartyName === '' || mb_strlen($counterpartyName) > 150) {
    $errors[] = 'نام طرف حساب الزامی است و باید کمتر از ۱۵۰ کاراکتر باشد.';
}

$amount = sanitizeAmount($rawAmount);
if ($amount <= 0) {
    $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.';
}
if ($amount > 999999999999) {
    $errors[] = 'مبلغ وارد شده بیش از حد بزرگ است.';
}

if (!isValidDate($entryDate)) {
    $errors[] = 'تاریخ ثبت نامعتبر است.';
}
if (!isValidDate($dueDate)) {
    $errors[] = 'تاریخ سررسید نامعتبر است.';
}

if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

// ---------- قسط‌بندی ----------
// ⚠ فقط وقتی خوانده می‌شود که هم ستون‌ها آمده باشند هم کاربر کلید را
//   زده باشد؛ وگرنه بدهیِ ساده دقیقاً مثل قبل ثبت می‌شود.
$instCount = null; $instEvery = null; $firstInst = null;
if (postParam('is_installment') === '1' && tableHasColumn('debts', 'installment_count')) {
    $instCount = (int)sanitizeAmount((string)postParam('installment_count'));
    // سقفِ ۴۸۰ یعنی ۴۰ سال ماهانه — بیشتر از هر وامِ واقعی، و جلوی
    // ساختنِ ده‌هزار رویداد در financialEvents را می‌گیرد.
    if ($instCount < 2 || $instCount > 480) {
        jsonResponse(['success' => false, 'message' => 'تعداد اقساط باید بین ۲ تا ۴۸۰ باشد.'], 422);
    }
    $instEvery = postParam('installment_every') === 'weekly' ? 'weekly' : 'monthly';
    $firstInst = (string)postParam('first_installment_date');
    if ($firstInst === '' || !isValidDate($firstInst)) { $firstInst = null; }
}

try {
    $hasInstCols = tableHasColumn('debts', 'installment_count');
    // ⚠ دو کوئریِ کامل، نه یک رشته‌ی ساخته‌شده با متغیر — قاعده ۴ درجِ
    //   متغیر در SQL را ممنوع کرده.
    $sql = $hasInstCols
        ? 'INSERT INTO debts (user_id, direction, counterparty_name, amount, note, entry_date, due_date,
                              installment_count, installment_every, first_installment_date)
           VALUES (:user_id, :direction, :counterparty_name, :amount, :note, :entry_date, :due_date,
                   :inst_count, :inst_every, :first_inst)'
        : 'INSERT INTO debts (user_id, direction, counterparty_name, amount, note, entry_date, due_date)
           VALUES (:user_id, :direction, :counterparty_name, :amount, :note, :entry_date, :due_date)';

    $params = [
        'user_id'           => Auth::userId(),
        'direction'         => $direction,
        'counterparty_name' => $counterpartyName,
        'amount'            => $amount,
        'note'              => $note !== '' ? $note : null,
        'entry_date'        => $entryDate,
        'due_date'          => $dueDate,
    ];
    if ($hasInstCols) {
        $params['inst_count'] = $instCount;
        $params['inst_every'] = $instEvery;
        $params['first_inst'] = $firstInst;
    }

    $pdo->prepare($sql)->execute($params);

    jsonResponse(['success' => true, 'message' => 'با موفقیت ثبت شد.']);
} catch (PDOException $e) {
    error_log('Add Debt Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی در ثبت رخ داد. دوباره تلاش کنید.'], 500);
}
