<?php
/**
 * ثبت، ویرایش، و «انجام شد» کردنِ یادآور.
 *
 * ⛔ یک اندپوینت برای هر سه، چون هر سه یک ردیف را می‌نویسند و جدا
 *    کردنشان یعنی سه جای مختلف که قاعده‌ی مالکیت را باید تکرار کنند.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();   // همیشه از اینجا، هرگز از ورودی کاربر

if (!Notify::remindersAvailable()) {
    jsonResponse(['success' => false, 'message' => 'جدول یادآورها هنوز ساخته نشده است.'], 400);
}

$pdo = Database::getConnection();
$id  = (int)postParam('id');

// ⛔ نگهبانِ «روزی یک بار» را باز کن.
//
//    بدونِ این، یادآوری که کاربر **برای همین امروز** ثبت می‌کند تا
//    فردا هیچ اعلانی نمی‌ساخت: تولیدکننده امروز یک بار اجرا شده بود و
//    نگهبانِ نشست جلوی اجرای دوباره را می‌گرفت. کاربر یادآور می‌ساخت،
//    زنگ خاموش می‌ماند، و نتیجه می‌گرفت که کار نمی‌کند.
unset($_SESSION['notify_scan']);

// ---------- «انجام شد» / برگرداندن ----------
if (postParam('toggle_done') === '1' && $id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM reminders WHERE id = :i AND user_id = :u');
    $stmt->execute(['i' => $id, 'u' => $userId]);
    $row = $stmt->fetch();
    if (!$row) { jsonResponse(['success' => false, 'message' => 'یادآور پیدا نشد.'], 404); }

    if ((int)$row['is_done'] === 1) {
        $pdo->prepare('UPDATE reminders SET is_done = 0 WHERE id = :i AND user_id = :u')
            ->execute(['i' => $id, 'u' => $userId]);
        jsonResponse(['success' => true, 'message' => 'یادآور دوباره فعال شد.']);
    }

    // ⛔ یادآورِ تکرارشونده «انجام شد» نمی‌ماند بلکه به دوره‌ی بعد
    //    می‌رود. اگر مثل یادآورِ یک‌باره بسته می‌شد، کاربرِ بیمه‌ی
    //    سالانه باید هر سال دستی یکی تازه می‌ساخت — یعنی همان کاری که
    //    قرار بود یادآور از دوشش بردارد.
    $rep = (string)$row['repeat_every'];
    if ($rep === 'monthly' || $rep === 'yearly') {
        // ⚠ از `advanceRecurringDate()` رد می‌شود، نه یک محاسبه‌ی تازه —
        //   همان تابعی که تراکنشِ دوره‌ای از آن استفاده می‌کند و روی
        //   تقویمِ **شمسی** جلو می‌رود (۳۱ فروردین + یک ماه = ۳۱
        //   اردیبهشت، نه ۳۰). با نسخه‌ی دوم، دو جای اپ دو تاریخ
        //   می‌گفتند.
        $next = advanceRecurringDate((string)$row['remind_date'], $rep === 'monthly' ? 'monthly' : 'yearly', 1);
        $pdo->prepare('
            UPDATE reminders SET remind_date = :d, last_notified_on = NULL
            WHERE id = :i AND user_id = :u
        ')->execute(['d' => $next, 'i' => $id, 'u' => $userId]);
        jsonResponse(['success' => true, 'message' => 'انجام شد — یادآور بعدی ' . toJalali($next)]);
    }

    $pdo->prepare('UPDATE reminders SET is_done = 1 WHERE id = :i AND user_id = :u')
        ->execute(['i' => $id, 'u' => $userId]);
    jsonResponse(['success' => true, 'message' => 'انجام شد.']);
}

// ---------- ثبت یا ویرایش ----------
$title = trim(postParam('title'));
$date  = postParam('remind_date');
$note  = trim(postParam('note'));
$rep   = postParam('repeat_every', 'none');
if (!in_array($rep, ['none', 'monthly', 'yearly'], true)) { $rep = 'none'; }

if ($title === '') { jsonResponse(['success' => false, 'message' => 'عنوان یادآور را بنویسید.'], 422); }
if (!isValidDate($date)) { jsonResponse(['success' => false, 'message' => 'تاریخ معتبر نیست.'], 422); }

$amountRaw = trim(postParam('amount'));
$amount    = $amountRaw === '' ? null : max(0, (int)sanitizeAmount($amountRaw));

$params = [
    'u' => $userId,
    't' => mb_substr($title, 0, 200),
    'd' => $date,
    'n' => $note === '' ? null : mb_substr($note, 0, 500),
    'a' => $amount,
    'r' => $rep,
];

if ($id > 0) {
    $params['i'] = $id;
    // ⚠ شرطِ `user_id` روی خودِ UPDATE است، نه یک بررسیِ جدا پیش از آن.
    $stmt = $pdo->prepare('
        UPDATE reminders
        SET title = :t, remind_date = :d, note = :n, amount = :a, repeat_every = :r,
            last_notified_on = NULL
        WHERE id = :i AND user_id = :u
    ');
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        // ردیف یا مالِ کسِ دیگری است یا هیچ چیزی عوض نشده — هر دو بی‌خطر.
        $chk = $pdo->prepare('SELECT 1 FROM reminders WHERE id = :i AND user_id = :u');
        $chk->execute(['i' => $id, 'u' => $userId]);
        if (!$chk->fetchColumn()) { jsonResponse(['success' => false, 'message' => 'یادآور پیدا نشد.'], 404); }
    }
    jsonResponse(['success' => true, 'message' => 'یادآور بروزرسانی شد.']);
}

$pdo->prepare('
    INSERT INTO reminders (user_id, title, remind_date, note, amount, repeat_every)
    VALUES (:u, :t, :d, :n, :a, :r)
')->execute($params);

jsonResponse(['success' => true, 'message' => 'یادآور ثبت شد.']);
