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

// ⛔ اینجا عمداً `apiRequirePlan()` نیست: این اندپوینت رکوردِ تازه
//    نمی‌سازد، فقط چیزی را که کاربر از قبل ثبت کرده ویرایش/تسویه/حذف
//    می‌کند. بستنش یعنی دفترِ کاربر روی واقعیتِ ماه‌ها پیش یخ می‌زند —
//    و دفترِ غلط از دفترِ نداشته بدتر است. قاعده ۲۶ در
//    `test_api_contract.php` این فهرست را بسته نگه می‌دارد.

$debtId = (int)postParam('debt_id');
$counterpartyName = postParam('counterparty_name');
$rawAmount = postParam('amount');
$note = postParam('note');
$entryDate = postParam('entry_date');
$dueDate = postParam('due_date');

if ($debtId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
}

$pdo = Database::getConnection();

$checkStmt = $pdo->prepare('SELECT id, user_id FROM debts WHERE id = :id');
$checkStmt->execute(['id' => $debtId]);
$debt = $checkStmt->fetch();

if (!$debt) {
    jsonResponse(['success' => false, 'message' => 'مورد یافت نشد.'], 404);
}

if ((int)$debt['user_id'] !== Auth::userId()) {
    jsonResponse(['success' => false, 'message' => 'شما اجازه ویرایش این مورد را ندارید.'], 403);
}

$errors = [];

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
// ⛔ سررسید اختیاری است (`migration_debt_optional_due`): خالی یعنی
//    «سررسید ندارد» و `NULL` ذخیره می‌شود — نه امروز، نه یک تاریخِ
//    ساختگی که بعد در «پول قابل خرج» به‌عنوان تعهدِ واقعی بنشیند.
//    ⚠ روی نصبی که migration نخورده ستون هنوز `NOT NULL` است، پس
//    آنجا همان رفتارِ قبلی (الزامی) می‌ماند و خطای روشن می‌دهد.
$dueDate = trim((string)$dueDate);
if ($dueDate === '') {
    $dueDate = null;
    if (!debtDueOptional()) {
        $errors[] = 'تاریخ سررسید الزامی است.';
    }
} elseif (!isValidDate($dueDate)) {
    $errors[] = 'تاریخ سررسید نامعتبر است.';
}

if (mb_strlen($note) > 1000) {
    $errors[] = 'توضیحات نباید بیشتر از ۱۰۰۰ کاراکتر باشد.';
}

// ⛔ وامِ قسطی بدونِ تاریخِ اولین قسط، لنگرش همان سررسید است؛ برداشتنِ
//    سررسید برنامه‌ی اقساط را به «از امروز به عقب» می‌برد (همه عقب‌افتاده).
if (empty($errors) && $dueDate === null && tableHasColumn('debts', 'installment_count')) {
    $iq = $pdo->prepare('SELECT installment_count, first_installment_date FROM debts
                         WHERE id = :id AND user_id = :u');
    $iq->execute(['id' => $debtId, 'u' => Auth::userId()]);
    $inst = $iq->fetch();
    if ($inst && (int)$inst['installment_count'] > 1 && empty($inst['first_installment_date'])) {
        $errors[] = 'این وام قسطی است و بدونِ سررسید برنامه‌ی اقساطش لنگر ندارد.';
    }
}

if (!empty($errors)) {
    jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422);
}

try {
    $stmt = $pdo->prepare('
        UPDATE debts
        SET counterparty_name = :counterparty_name, amount = :amount, note = :note,
            entry_date = :entry_date, due_date = :due_date
        WHERE id = :id AND user_id = :user_id
    ');
    $stmt->execute([
        'counterparty_name' => $counterpartyName,
        'amount'            => $amount,
        'note'              => $note !== '' ? $note : null,
        'entry_date'        => $entryDate,
        'due_date'          => $dueDate,
        'id'                => $debtId,
        'user_id'           => Auth::userId(),
    ]);

    jsonResponse(['success' => true, 'message' => 'با موفقیت ویرایش شد.']);
} catch (PDOException $e) {
    Log::error('api.update_debt', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی در ویرایش رخ داد.'], 500);
}
