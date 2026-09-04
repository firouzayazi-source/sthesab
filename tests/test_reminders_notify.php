<?php
/**
 * یادآورهای شخصی و مرکزِ اعلان.
 *
 * ⛔ خطرناک‌ترین چیزِ این قابلیت **تکرار** است، نه نبودن: اگر هر بازدیدِ
 *    صفحه همان سررسید را دوباره اعلان کند، فهرست در دو روز پر از
 *    تکراری می‌شود و کاربر دیگر نگاهش نمی‌کند — یعنی قابلیت عملاً
 *    می‌میرد، بی‌آنکه هیچ خطایی بدهد. پس `dedup_key` اولین چیزی است که
 *    سنجیده می‌شود.
 *
 * ⛔ دومین چیز جداسازیِ کاربران است: اعلانِ یک نفر نباید به دستِ دیگری
 *    برسد و یادآورِ کسی نباید با شناسه‌اش پاک شود.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('یادآور و اعلان');
    T::skip('تست یادآور و اعلان', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';

$pdo = Database::getConnection();

if (!Notify::available() || !Notify::remindersAvailable()) {
    T::group('یادآور و اعلان');
    T::skip('تست یادآور و اعلان', 'migration_notifications.sql هنوز اجرا نشده');
    exit(T::report());
}

// ---------- قربانی‌ها را خودمان می‌سازیم ----------
// ⚠ تکیه بر داده‌ی موجود یعنی روی دیتابیسِ خالی تست بی‌صدا رد می‌شود و
//   هیچ چیزی را نگه نمی‌دارد.
$mk = function (string $u) use ($pdo): int {
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $u]);
    $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES (:n, :u, :p, "user", 1)')
        ->execute(['n' => 'تست ' . $u, 'u' => $u, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};
$uidA = $mk('__notif_a__');
$uidB = $mk('__notif_b__');

$cleanup = function () use ($pdo) {
    foreach (['__notif_a__', '__notif_b__'] as $u) {
        $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $u]);
    }
};

try {
    $today = today();

    // ---------------------------------------------------------------
    T::group('⛔ یک رویداد، یک اعلان — هر چند بار که تولید اجرا شود');

    $key = 'test:once';
    T::ok(Notify::push($uidA, 'due', 'چک بانک ملت', 'سررسید امروز', 'cheques.php', $key),
        'بارِ اول ثبت می‌شود');
    T::ok(!Notify::push($uidA, 'due', 'چک بانک ملت', 'سررسید امروز', 'cheques.php', $key),
        '⛔ بارِ دوم با همان کلید ثبت نمی‌شود');

    $n = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uidA")->fetchColumn();
    T::same(1, $n, 'و در دیتابیس فقط یک ردیف مانده');

    // ---------------------------------------------------------------
    T::group('⛔ اعلانِ هر کاربر فقط مالِ خودش');

    Notify::push($uidB, 'due', 'مالِ کاربرِ دوم', '', '', 'test:b');
    T::same(1, Notify::unreadCount($uidA), 'شمارشِ نخوانده‌ی A فقط مالِ A است');
    T::same(1, Notify::unreadCount($uidB), 'و شمارشِ B فقط مالِ B');

    $titles = array_column(Notify::recent($uidA), 'title');
    T::ok(!in_array('مالِ کاربرِ دوم', $titles, true), '⛔ فهرستِ A اعلانِ B را ندارد');

    // ⚠ همان کلید برای کاربرِ دیگر باید **بپذیرد** — یکتایی روی
    //   (user_id, dedup_key) است نه روی خودِ کلید. وگرنه اولین کاربری که
    //   یک سررسید داشت، جلوی اعلانِ همه‌ی بقیه را می‌گرفت.
    T::ok(Notify::push($uidB, 'due', 'چک بانک ملت', '', '', 'test:once'),
        '⛔ همان کلید برای کاربرِ دیگر پذیرفته می‌شود');

    // ---------------------------------------------------------------
    T::group('خوانده‌شدن');

    Notify::markRead($uidA);
    T::same(0, Notify::unreadCount($uidA), 'بعد از markRead نخوانده‌ای نمی‌ماند');
    T::same(2, Notify::unreadCount($uidB), 'و به کاربرِ دیگر دست نمی‌زند');

    // ---------------------------------------------------------------
    T::group('یادآورِ سررسیدشده اعلان می‌سازد');

    $pdo->prepare('INSERT INTO reminders (user_id, title, remind_date, amount, repeat_every)
                   VALUES (:u, :t, :d, :a, "none")')
        ->execute(['u' => $uidA, 't' => 'بیمه آتش‌سوزی', 'd' => $today, 'a' => 2500000]);
    $rid = (int)$pdo->lastInsertId();

    $_SESSION = [];
    $made = Notify::generateFor($uidA, true);
    T::ok($made >= 1, 'یادآورِ امروز اعلان ساخت', "ساخته شد: {$made}");

    $has = false;
    foreach (Notify::recent($uidA) as $x) { if ($x['title'] === 'بیمه آتش‌سوزی') { $has = true; } }
    T::ok($has, 'و عنوانش در فهرست دیده می‌شود');

    // ⛔ اجرای دوباره نباید تکراری بسازد — مهم‌ترین بررسیِ این تست.
    $again = Notify::generateFor($uidA, true);
    T::same(0, $again, '⛔ اجرای دوباره‌ی تولید، اعلانِ تکراری نمی‌سازد');

    // ---------------------------------------------------------------
    T::group('یادآورِ انجام‌شده دیگر اعلان نمی‌دهد');

    $pdo->prepare('UPDATE reminders SET is_done = 1, last_notified_on = NULL WHERE id = :i')
        ->execute(['i' => $rid]);
    $pdo->prepare('DELETE FROM notifications WHERE user_id = :u')->execute(['u' => $uidA]);
    Notify::generateFor($uidA, true);
    $c = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uidA AND kind = 'reminder'")->fetchColumn();
    T::same(0, $c, 'یادآورِ بسته‌شده اعلان نمی‌سازد');

    // ---------------------------------------------------------------
    T::group('یادآورِ آینده هنوز اعلان نمی‌دهد');

    $future = date('Y-m-d', strtotime($today . ' +20 day'));
    $pdo->prepare('INSERT INTO reminders (user_id, title, remind_date, repeat_every)
                   VALUES (:u, "عوارض خودرو", :d, "none")')
        ->execute(['u' => $uidA, 'd' => $future]);
    $pdo->prepare('DELETE FROM notifications WHERE user_id = :u')->execute(['u' => $uidA]);
    Notify::generateFor($uidA, true);
    $c = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uidA AND kind = 'reminder'")->fetchColumn();
    T::same(0, $c, '⛔ یادآورِ بیستِ روزِ آینده امروز اعلان نمی‌دهد');

    // ---------------------------------------------------------------
    T::group('تاریخِ دوره‌ی بعد روی تقویمِ شمسی جلو می‌رود');

    // ۳۰ اسفند ۱۴۰۳ (سالِ کبیسه) + یک سال باید ۲۹ اسفند ۱۴۰۴ شود، نه
    // یک تاریخِ ناموجود.
    $leap = jalaliToGregorian(1403, 12, 30);
    $src  = sprintf('%04d-%02d-%02d', $leap[0], $leap[1], $leap[2]);
    $next = advanceRecurringDate($src, 'yearly', 1);
    // ⚠ از `gregorianToJalali()` خوانده می‌شود، نه از تجزیه‌ی خروجیِ
    //   `toJalali()` — آن تابع **ارقام فارسی** می‌دهد و `intval` رویش صفر
    //   می‌شود. نسخه‌ی اولِ همین تست دقیقاً همین‌جا افتاد.
    [$ny, $nm, $nd] = array_map('intval', explode('-', $next));
    [$jy, $jm, $jd] = gregorianToJalali($ny, $nm, $nd);
    T::same(1404, $jy, 'سالِ بعدی درست است');
    T::same(12, $jm, 'ماه اسفند می‌ماند');
    T::ok($jd <= jalaliMonthLength(1404, 12),
        '⛔ روز به طولِ واقعیِ آن ماه محدود می‌شود', "روز: {$jd}");

    // ---------------------------------------------------------------
    T::group('یادآور در ایمیلِ روزانه هم می‌آید');

    $ev = customReminderEvents($uidA, $today, date('Y-m-d', strtotime($today . ' +30 day')));
    T::ok(count($ev) >= 1, 'رویدادِ یادآور ساخته می‌شود', 'تعداد: ' . count($ev));
    if ($ev) {
        // ⚠ شکلش باید با `financialEvents()` یکی باشد، وگرنه سازنده‌ی
        //   ایمیل روی کلیدِ نبوده می‌افتد.
        foreach (['date', 'kind', 'direction', 'title', 'amount', 'url'] as $k) {
            T::ok(array_key_exists($k, $ev[0]), "کلیدِ «{$k}» در رویداد هست");
        }
    }

    // ---------------------------------------------------------------
    // ⛔ رگرسیونِ واقعی: با آمدنِ هسته‌ی مشترکِ سررسید،
    //    `syncScheduleRules()` برای هر چک و طلب و بدهی یک ردیف در همین
    //    جدولِ `reminders` می‌سازد. کوئریِ صفحه‌ی «یادآورهای من» پیش از
    //    آن نوشته شده بود و شرطِ `source_type` نداشت، پس آن صفحه پر شد
    //    از قانون‌هایی که **بخشِ خودشان** از قبل خبرشان را می‌دهد — و
    //    کاربر همان چک را دو جا می‌دید.
    T::group('⛔ صفحه‌ی یادآور فقط یادآورِ دلخواه را نشان می‌دهد');

    $pdo->prepare("INSERT INTO reminders (user_id, source_type, source_id, title, remind_date)
                   VALUES (:u, 'cheque', 987654, 'چکِ ساختگیِ تست', :d)")
        ->execute(['u' => $uidA, 'd' => $today]);

    // همان کوئریِ خودِ `reminders.php` — نه یک نسخه‌ی تازه، وگرنه تست
    // چیزی را می‌سنجد که صفحه نمی‌سنجد.
    $listSql = "SELECT title FROM reminders
                WHERE user_id = :u AND (source_type = 'custom' OR source_type IS NULL)";
    $st = $pdo->prepare($listSql);
    $st->execute(['u' => $uidA]);
    $titles = $st->fetchAll(PDO::FETCH_COLUMN);

    T::ok(!in_array('چکِ ساختگیِ تست', $titles, true),
        '⛔ قانونِ مشتق‌شده از چک در فهرستِ «یادآورهای من» نمی‌آید');

    $allSt = $pdo->prepare('SELECT COUNT(*) FROM reminders WHERE user_id = :u');
    $allSt->execute(['u' => $uidA]);
    T::ok((int)$allSt->fetchColumn() > count($titles),
        'ولی ردیفش در جدول هست — فقط از این صفحه بیرون است، نه پاک شده');

    // ---------------------------------------------------------------
    // ⛔ «چند روز قبل خبر بده» تا امروز ذخیره می‌شد ولی هیچ‌جا خوانده
    //    نمی‌شد: تولیدکننده فقط `remind_date <= today` را می‌گرفت. یعنی
    //    کاربر «یک هفته قبل» را می‌زد، ذخیره هم می‌شد، و اعلان باز هم
    //    روزِ خودِ سررسید می‌آمد — تنظیمی که کار نمی‌کند.
    T::group('⛔ روزهای پیش‌آگاهی واقعاً اثر دارند');

    $in7 = date('Y-m-d', strtotime($today . ' +7 day'));
    $in5 = date('Y-m-d', strtotime($today . ' +5 day'));

    $pdo->prepare("INSERT INTO reminders (user_id, source_type, title, remind_date, notify_days_before)
                   VALUES (:u, 'custom', 'بیمه با هشدارِ هفت‌روزه', :d, '[7,1]')")
        ->execute(['u' => $uidA, 'd' => $in7]);
    $pdo->prepare("INSERT INTO reminders (user_id, source_type, title, remind_date, notify_days_before)
                   VALUES (:u, 'custom', 'بیمه بدونِ هشدارِ پنج‌روزه', :d, '[7,1]')")
        ->execute(['u' => $uidA, 'd' => $in5]);

    unset($_SESSION['notify_scan']);
    Notify::generateFor($uidA);
    $seen = array_column(Notify::recent($uidA), 'title');

    T::ok(in_array('بیمه با هشدارِ هفت‌روزه', $seen, true),
        '⛔ سررسیدِ هفت روز بعد، با انتخابِ «یک هفته قبل»، همین امروز اعلان می‌دهد');
    T::ok(!in_array('بیمه بدونِ هشدارِ پنج‌روزه', $seen, true),
        '⛔ ولی پنج روز بعد اعلان نمی‌دهد — بازه‌ی ۵ انتخاب نشده بود');

    // ---------------------------------------------------------------
    // ⛔ «۱۲ قسط، ۱۲ میلیون» نباید ۱۲ ردیف بسازد و نباید باقیمانده را
    //    گم کند. باقیمانده به قسطِ آخر می‌رود — همان قاعده‌ی
    //    `debtInstallments()`؛ بدونش جمعِ اقساط با تعهد نمی‌خواند.
    T::group('⛔ اقساط: باقیمانده به قسطِ آخر');

    $rule = ['total_count' => 3, 'total_amount' => 10_000_000, 'done_count' => 0, 'amount' => 0];
    T::same(3_333_333, Schedule::installmentAmount($rule, 1), 'قسط اول');
    T::same(3_333_333, Schedule::installmentAmount($rule, 2), 'قسط دوم');
    T::same(3_333_334, Schedule::installmentAmount($rule, 3), '⛔ قسط آخر باقیمانده را می‌گیرد');

    $sum = 0;
    for ($i = 1; $i <= 3; $i++) { $sum += Schedule::installmentAmount($rule, $i); }
    T::same(10_000_000, $sum, '⛔ جمعِ اقساط دقیقاً با تعهد می‌خواند');

    T::same(3_333_333, Schedule::installmentAmount($rule),
        'بدونِ شماره، قسطِ جاری از done_count خوانده می‌شود');
    T::same(3_333_334, Schedule::installmentAmount(['total_count' => 3, 'total_amount' => 10_000_000,
        'done_count' => 2, 'amount' => 0]), 'و با done_count = ۲ همان قسطِ آخر است');

    T::same(500, Schedule::installmentAmount(['total_count' => 0, 'total_amount' => 0, 'amount' => 500]),
        '⚠ بدونِ تعهدِ چندقسطی، همان مبلغِ خام برمی‌گردد (یادآورهای موجود دست‌نخورده)');

    // ---------------------------------------------------------------
    // ⛔ دوره‌ی روزانه نباید از تقویمِ ماهانه رد شود: «هر ۱۵ روز» در هر
    //    تقویمی دقیقاً ۱۵ روز است و ترجمه‌اش به ماه بی‌معناست.
    T::group('⛔ تکرارِ روزانه دقیقاً روزانه است');

    T::same(date('Y-m-d', strtotime($today . ' +15 day')),
        Schedule::nextDue(['recurrence_type' => 'every_n_days', 'recurrence_n' => 15], $today),
        'هر ۱۵ روز → دقیقاً ۱۵ روز بعد');
    T::same(date('Y-m-d', strtotime($today . ' +7 day')),
        Schedule::nextDue(['recurrence_type' => 'every_n_days', 'recurrence_n' => 7], $today),
        'هفتگی → دقیقاً ۷ روز بعد');
    T::same(null, Schedule::nextDue(['recurrence_type' => 'once'], $today),
        'یک‌باره تاریخِ بعدی ندارد');

    // ⛔ فهرستِ چیپ‌های فرم و فهرستِ انواعِ مجازِ اندپوینت باید یکی باشند،
    //    وگرنه گزینه‌ای که کاربر می‌بیند هنگام ذخیره بی‌صدا به «یک بار»
    //    برمی‌گردد — همان چیزی که سرِ `SESSION_WINDOWS` گفته شد.
    foreach (Schedule::RECUR_PRESETS as $key => $p) {
        T::ok(in_array($p['type'], Schedule::RECUR_TYPES, true),
            "⛔ نوعِ چیپِ «{$p['label']}» در فهرستِ مجازِ اندپوینت هست");
        T::same($key, Schedule::presetKey($p['type'], $p['n']),
            "کلیدِ چیپِ «{$p['label']}» برگشت‌پذیر است (پر کردنِ فرمِ ویرایش)");
    }
    T::same('custom', Schedule::presetKey('every_n_months', 4),
        '⛔ «هر ۴ ماه» با هیچ چیپی نمی‌خواند، پس «دلخواه» است');

    // ---------------------------------------------------------------
    T::group('پاک کردن فقط مالِ خودِ کاربر است');

    Notify::deleteAll($uidA);
    T::same(0, count(Notify::recent($uidA)), 'اعلان‌های A پاک شدند');
    T::ok(count(Notify::recent($uidB)) > 0, '⛔ و اعلان‌های B دست‌نخورده ماندند');
} finally {
    $cleanup();
}

exit(T::report());
