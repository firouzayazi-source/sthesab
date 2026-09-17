<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

// ⛔ قفلِ صفحه بدونِ این فقط تزئین است: کسی که آدرسِ اندپوینت را
//    بداند مستقیم صدایش می‌زند.
require_once __DIR__ . '/../includes/plan_gate.php';

$userId    = Auth::userId();
$id        = (int)postParam('recurring_id');

// ⛔ این تنها اندپوینتی است که هم می‌سازد هم ویرایش می‌کند، پس گیت
//    **بعد از** خواندنِ شناسه می‌آید: قانونِ تازه اشتراک می‌خواهد،
//    ویرایشِ قانونی که کاربر از قبل ساخته نه. با گیتِ بالای فایل،
//    کاربرِ منقضی نمی‌توانست مبلغِ قبضِ همیشگی‌اش را هم درست کند.
if ($id === 0) { apiRequirePlan('recurring'); }
$type      = postParam('type');
$title     = postParam('title');
$amount    = sanitizeAmount(postParam('amount'));
$categoryId= postParam('category_id');
$walletId  = postParam('wallet_id');
$note      = postParam('note');
$frequency = postParam('frequency', 'monthly');
$interval  = max(1, (int)postParam('interval_count', 1));
$startDate = postParam('start_date');
$endDate   = postParam('end_date');
$mode      = postParam('mode', 'remind');

$errors = [];
if (!in_array($type, ['income', 'expense'], true)) { $errors[] = 'نوع نامعتبر است.'; }
if ($title === '' || mb_strlen($title) > 255) { $errors[] = 'عنوان الزامی است.'; }
if ($amount <= 0) { $errors[] = 'مبلغ باید بزرگ‌تر از صفر باشد.'; }
if ($amount > 999999999999) { $errors[] = 'مبلغ بیش از حد بزرگ است.'; }
if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) { $errors[] = 'دوره تکرار نامعتبر است.'; }
if ($interval < 1 || $interval > 60) { $errors[] = 'فاصله تکرار نامعتبر است.'; }
if (!isValidDate($startDate)) { $errors[] = 'تاریخ شروع نامعتبر است.'; }
if ($endDate !== '' && !isValidDate($endDate)) { $errors[] = 'تاریخ پایان نامعتبر است.'; }
if ($endDate !== '' && $endDate < $startDate) { $errors[] = 'تاریخ پایان نمی‌تواند قبل از شروع باشد.'; }
if (!in_array($mode, ['auto', 'remind', 'confirm'], true)) { $mode = 'remind'; }

$pdo = Database::getConnection();

$categoryIdValue = null;
if ($categoryId !== '' && (int)$categoryId > 0) {
    $c = $pdo->prepare(
        'SELECT id FROM categories
         WHERE id = :id AND type = :t AND is_active = 1 AND ' . categoryScopeSql()
    );
    $c->execute(['id' => (int)$categoryId, 't' => $type] + categoryScopeParams($userId));
    if ($c->fetch()) { $categoryIdValue = (int)$categoryId; }
}

$walletIdValue = null;
if ($walletId !== '' && (int)$walletId > 0) {
    $w = $pdo->prepare('SELECT id FROM wallets WHERE id = :id AND user_id = :u');
    $w->execute(['id' => (int)$walletId, 'u' => $userId]);
    if ($w->fetch()) { $walletIdValue = (int)$walletId; }
}

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

try {
    if ($id > 0) {
        $own = $pdo->prepare('SELECT id, next_due_date, start_date FROM recurring_transactions WHERE id = :id AND user_id = :u');
        $own->execute(['id' => $id, 'u' => $userId]);
        $existing = $own->fetch();
        if (!$existing) { jsonResponse(['success' => false, 'message' => 'یافت نشد.'], 404); }

        // اگر تاریخ شروع تغییر کرده و هنوز هیچ تراکنشی از این قانون تولید نشده، سررسید بعدی هم به‌روز شود
        $nextDue = $startDate > $existing['next_due_date'] || $startDate !== $existing['start_date']
            ? $startDate : $existing['next_due_date'];

        $stmt = $pdo->prepare('
            UPDATE recurring_transactions
            SET type=:type, title=:title, amount=:amount, category_id=:cat, wallet_id=:wal, note=:note,
                frequency=:freq, interval_count=:interval, start_date=:sd, end_date=:ed, mode=:mode, next_due_date=:nd
            WHERE id=:id AND user_id=:u
        ');
        $stmt->execute([
            'type' => $type, 'title' => $title, 'amount' => $amount, 'cat' => $categoryIdValue, 'wal' => $walletIdValue,
            'note' => $note !== '' ? $note : null, 'freq' => $frequency, 'interval' => $interval,
            'sd' => $startDate, 'ed' => $endDate !== '' ? $endDate : null, 'mode' => $mode, 'nd' => $nextDue,
            'id' => $id, 'u' => $userId,
        ]);
        invalidateRecurringCache($userId);
    jsonResponse(['success' => true, 'message' => 'بروزرسانی شد.']);
    }

    $stmt = $pdo->prepare('
        INSERT INTO recurring_transactions
            (user_id, wallet_id, category_id, type, amount, title, note, frequency, interval_count, start_date, end_date, next_due_date, mode)
        VALUES
            (:u, :wal, :cat, :type, :amount, :title, :note, :freq, :interval, :sd, :ed, :nd, :mode)
    ');
    $stmt->execute([
        'u' => $userId, 'wal' => $walletIdValue, 'cat' => $categoryIdValue, 'type' => $type, 'amount' => $amount,
        'title' => $title, 'note' => $note !== '' ? $note : null, 'freq' => $frequency, 'interval' => $interval,
        'sd' => $startDate, 'ed' => $endDate !== '' ? $endDate : null, 'nd' => $startDate, 'mode' => $mode,
    ]);
    invalidateRecurringCache($userId);
    jsonResponse(['success' => true, 'message' => 'ثبت شد.']);
} catch (PDOException $e) {
    Log::error('api.save_recurring', $e);
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
