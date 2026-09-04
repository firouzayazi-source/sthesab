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
    // ⛔ تاریخِ بعدی از `jalaliAddMonths()` می‌آید — همان تابعی که هسته‌ی
    //    مشترکِ سررسید از آن رد می‌شود و **روزِ لنگر** می‌گیرد. بدونِ
    //    لنگر، ۳۱ فروردین بعد از یک بار افتادن در یک ماهِ ۳۰ روزه برای
    //    همیشه ۳۰ می‌ماند.
    $rt = (string)($row['recurrence_type'] ?? 'once');
    $rn = max(1, (int)($row['recurrence_n'] ?? 1));
    if ($rt === 'yearly' || $rt === 'every_n_months') {
        $step   = $rt === 'yearly' ? 12 : $rn;
        $anchor = (int)($row['anchor_day'] ?? 0);
        $next   = jalaliAddMonths((string)$row['remind_date'], $step, $anchor,
                                  (string)($row['day_rule'] ?? 'fixed_day'));
        $pdo->prepare('
            UPDATE reminders SET remind_date = :d, last_notified_on = NULL
            WHERE id = :i AND user_id = :u
        ')->execute(['d' => $next, 'i' => $id, 'u' => $userId]);
        unset($_SESSION['notify_scan']);
        jsonResponse(['success' => true, 'message' => 'انجام شد — یادآور بعدی ' . toJalali($next)]);
    }

    // ⚠ نصب‌های قدیمی که هنوز فقط `repeat_every` دارند.
    $rep = (string)($row['repeat_every'] ?? 'none');
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

// ---------- به تأخیر انداختن ----------
// ⛔ سومین اقدام، و جدا از دو تای دیگر: «انجام شد» یعنی پرداختش کردم،
//    «حذف» یعنی دیگر لازمش ندارم. بدونِ «تأخیر»، کسی که قبضش را هنوز
//    نداده مجبور بود یکی از آن دو دروغ را بگوید.
//
// ⚠ مبنا **خودِ سررسید** است نه امروز، وگرنه یادآورِ عقب‌افتاده با یک
//   تأخیرِ هفت‌روزه به سه هفته بعد می‌پرید. ولی اگر از امروز عقب‌تر
//   باشد، از امروز حساب می‌شود تا نتیجه در گذشته نماند.
if ($id > 0 && postParam('snooze_days') !== '') {
    $days = max(1, min(365, (int)postParam('snooze_days')));

    $stmt = $pdo->prepare('SELECT remind_date FROM reminders WHERE id = :i AND user_id = :u');
    $stmt->execute(['i' => $id, 'u' => $userId]);
    $base = (string)$stmt->fetchColumn();
    if ($base === '') { jsonResponse(['success' => false, 'message' => 'یادآور پیدا نشد.'], 404); }

    $from = max($base, today());
    $next = date('Y-m-d', strtotime($from . ' +' . $days . ' day'));

    $pdo->prepare('
        UPDATE reminders SET remind_date = :d, is_done = 0, last_notified_on = NULL
        WHERE id = :i AND user_id = :u
    ')->execute(['d' => $next, 'i' => $id, 'u' => $userId]);

    unset($_SESSION['notify_scan']);
    jsonResponse(['success' => true, 'message' => 'به ' . toJalali($next) . ' موکول شد.']);
}

// ---------- ثبت یا ویرایش ----------
$title = trim(postParam('title'));
$date  = postParam('remind_date');
$note  = trim(postParam('note'));
// ⛔ مدلِ تکرار حالا یکی است: نوع + N. «هر سال» فقط N=۱۲ نیست بلکه
//    نوعِ خودش را دارد، چون کاربر «سالانه» را می‌فهمد و «۱۲ ماه» را
//    باید در ذهنش ترجمه کند.
$rtype = postParam('recurrence_type', 'once');
if (!in_array($rtype, ['once', 'every_n_months', 'yearly'], true)) { $rtype = 'once'; }
$rn = max(1, min(60, (int)postParam('recurrence_n', '1')));

// ⚠ `repeat_every` هنوز نوشته می‌شود: نصب‌ها و کدِ قدیمی از آن می‌خوانند
//   و رهایش کردن یعنی یادآورِ تکرارشونده در آن مسیرها یک‌باره دیده شود.
$rep = match ($rtype) {
    'yearly'         => 'yearly',
    'every_n_months' => $rn === 12 ? 'yearly' : 'monthly',
    default          => 'none',
};

// ⛔ چند بازه با هم. ورودی از کاربر می‌آید پس هر مقدارِ بی‌ربطی کنار
//    گذاشته می‌شود، وگرنه یک JSON آشغال در ستون می‌نشیند و کرونِ اعلان
//    بی‌صدا هیچ چیزی نمی‌فرستد.
$daysIn  = (array)($_POST['notify_days'] ?? []);
$allowed = [1, 3, 7, 30];
$days    = array_values(array_unique(array_filter(
    array_map('intval', $daysIn),
    fn ($d) => in_array($d, $allowed, true)
)));
if (!$days) { $days = [1]; }   // خالی یعنی «هیچ اعلانی» — که یعنی یادآورِ بی‌فایده
rsort($days);

if ($title === '') { jsonResponse(['success' => false, 'message' => 'عنوان یادآور را بنویسید.'], 422); }
if (!isValidDate($date)) { jsonResponse(['success' => false, 'message' => 'تاریخ معتبر نیست.'], 422); }

$amountRaw = trim(postParam('amount'));
$amount    = $amountRaw === '' ? null : max(0, (int)sanitizeAmount($amountRaw));

// ⛔ روزِ لنگر از روزِ **شمسیِ** خودِ تاریخ برداشته می‌شود، نه میلادی —
//    وگرنه «۳۱ فروردین» به روزِ ۲۰ آوریل لنگر می‌خورد و تکرار بی‌معنا
//    می‌شود.
[, , $anchorDay] = gregorianToJalali(...array_map('intval', explode('-', $date)));

$params = [
    'u'  => $userId,
    't'  => mb_substr($title, 0, 200),
    'd'  => $date,
    'n'  => $note === '' ? null : mb_substr($note, 0, 500),
    'a'  => $amount,
    'r'  => $rep,
    'rt' => $rtype,
    'rn' => $rn,
    'ad' => $anchorDay,
    'nd' => json_encode($days),
];

if ($id > 0) {
    $params['i'] = $id;
    // ⚠ شرطِ `user_id` روی خودِ UPDATE است، نه یک بررسیِ جدا پیش از آن.
    $stmt = $pdo->prepare("
        UPDATE reminders
        SET title = :t, remind_date = :d, note = :n, amount = :a, repeat_every = :r,
            recurrence_type = :rt, recurrence_n = :rn, anchor_day = :ad,
            notify_days_before = :nd, last_notified_on = NULL
        WHERE id = :i AND user_id = :u
          AND (source_type = 'custom' OR source_type IS NULL)
    ");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        // ردیف یا مالِ کسِ دیگری است یا هیچ چیزی عوض نشده — هر دو بی‌خطر.
        $chk = $pdo->prepare("SELECT 1 FROM reminders WHERE id = :i AND user_id = :u
                              AND (source_type = 'custom' OR source_type IS NULL)");
        $chk->execute(['i' => $id, 'u' => $userId]);
        if (!$chk->fetchColumn()) { jsonResponse(['success' => false, 'message' => 'یادآور پیدا نشد.'], 404); }
    }
    jsonResponse(['success' => true, 'message' => 'یادآور بروزرسانی شد.']);
}

$pdo->prepare('
    INSERT INTO reminders (user_id, source_type, title, remind_date, note, amount,
                           repeat_every, recurrence_type, recurrence_n, anchor_day,
                           notify_days_before)
    VALUES (:u, :st, :t, :d, :n, :a, :r, :rt, :rn, :ad, :nd)
// ⛔ صریح: این صفحه فقط یادآورِ **دلخواه** می‌سازد. قانون‌های چک و بدهی را
//    `syncScheduleRules()` می‌سازد؛ اگر اینجا هم `custom` نمی‌نشست، ردیف
//    از فهرستِ «یادآورهای من» بیرون می‌ماند و کاربر فکر می‌کرد ذخیره نشد.
// ⚠ فقط به INSERT داده می‌شود، نه به UPDATE — پارامترِ bind شده‌ای که در
//   SQL نباشد با `EMULATE_PREPARES=false` خطا می‌دهد.
')->execute($params + ['st' => 'custom']);

jsonResponse(['success' => true, 'message' => 'یادآور ثبت شد.']);
