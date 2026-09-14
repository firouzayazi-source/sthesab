<?php
/**
 * موتورِ تکرارِ سررسید.
 *
 * ⛔ خطرناک‌ترین چیزِ این موتور **بی‌صدا** بودنِ خطاست: یک روز جابه‌جایی
 *    در قسط یا بیمه هیچ خطایی نمی‌دهد، فقط تاریخ غلط می‌شود و کاربر
 *    ماه‌ها بعد می‌فهمد. پس تاریخ‌های مرزیِ تقویمِ شمسی اینجا با عددِ
 *    دقیق سنجیده می‌شوند، نه با «اجرا شد و خطا نداد».
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('موتور سررسید');
    T::blocked('تست موتور سررسید', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';

/** تاریخِ شمسی → میلادی، برای نوشتنِ خواناترِ تست. */
$g = function (int $jy, int $jm, int $jd): string {
    $x = jalaliToGregorian($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $x[0], $x[1], $x[2]);
};
/** میلادی → «۱۴۰۵/۰۷/۰۱» با ارقامِ لاتین، برای مقایسه‌ی خوانا. */
$j = function (string $greg): string {
    [$gy, $gm, $gd] = array_map('intval', explode('-', $greg));
    [$jy, $jm, $jd] = gregorianToJalali($gy, $gm, $gd);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
};

// ---------------------------------------------------------------
T::group('طولِ ماه‌های شمسی — پایه‌ی همه‌ی حساب‌ها');

// ⚠ شش ماهِ اول ۳۱، شش ماهِ دوم ۳۰، و اسفند ۲۹ یا ۳۰ بسته به کبیسه.
//   این از تبدیلِ واقعیِ تقویم می‌آید نه از فرمولِ تقریبی.
$bad = [];
for ($m = 1; $m <= 6; $m++)  { if (jalaliMonthLength(1404, $m) !== 31) { $bad[] = "ماه $m"; } }
for ($m = 7; $m <= 11; $m++) { if (jalaliMonthLength(1404, $m) !== 30) { $bad[] = "ماه $m"; } }
T::bulk(11, $bad, 'فروردین تا شهریور ۳۱ روز، مهر تا بهمن ۳۰ روز');

T::same(30, jalaliMonthLength(1403, 12), '۱۴۰۳ کبیسه است — اسفند ۳۰ روز');
T::same(29, jalaliMonthLength(1404, 12), '۱۴۰۴ کبیسه نیست — اسفند ۲۹ روز');

// ---------------------------------------------------------------
T::group('۳۱ فروردین + ۳ ماه');

// فروردین ۳۱ روزه، تیر هم ۳۱ روزه → باید دقیقاً ۳۱ تیر شود.
T::same('1405/04/31', $j(jalaliAddMonths($g(1405, 1, 31), 3, 31)),
    '۳۱ فروردین + ۳ ماه = ۳۱ تیر');

// ---------------------------------------------------------------
T::group('⛔ روزِ لنگر بعد از افتادن در ماهِ کوتاه گم نمی‌شود');

// ۳۱ شهریور + ۱ ماه → مهر ۳۰ روزه است، پس ۳۰ مهر.
$step1 = jalaliAddMonths($g(1405, 6, 31), 1, 31);
T::same('1405/07/30', $j($step1), '۳۱ شهریور + ۱ ماه = ۳۰ مهر (کوتاه می‌آید)');

// ⛔ و گامِ بعدی باید دوباره از **۳۱** حساب شود، نه از ۳۰.
//    آبان هم ۳۰ روزه است پس باز ۳۰ می‌شود، ولی آذر… هم ۳۰ است.
//    گامِ چهارم به دی می‌رسد که ۳۰ روزه است. پس تا فروردینِ بعد صبر
//    می‌کنیم: ۳۱ شهریور + ۷ ماه = فروردین، که ۳۱ روزه است.
$step7 = jalaliAddMonths($g(1405, 6, 31), 7, 31);
T::same('1406/01/31', $j($step7),
    '⛔ ۳۱ شهریور + ۷ ماه = ۳۱ فروردین — روزِ لنگر برگشت');

// و اگر لنگر داده نشود (رفتارِ قدیمی) روز از خودِ تاریخ برداشته می‌شود.
T::same('1405/07/30', $j(jalaliAddMonths($step1, 0, 0)),
    'بدونِ لنگر، روز از خودِ تاریخ می‌آید');

// ⛔ و **این** تستِ اصلیِ لنگر است: زنجیره، نه یک پرشِ تکی.
//
//    باگ فقط وقتی دیده می‌شود که خروجیِ هر گام ورودیِ گامِ بعد باشد —
//    یعنی همان کاری که `Schedule::materialize()` می‌کند. با یک پرشِ
//    هفت‌ماهه از مبدأ، حتی نسخه‌ی باگ‌دار هم جوابِ درست می‌داد، چون روزِ
//    مبدأ خودش ۳۱ بود. نسخه‌ی اولِ همین تست دقیقاً همین‌جا کور بود و
//    جهش نشانش داد.
$chain = $g(1405, 6, 31);
$seen  = [];
for ($i = 0; $i < 7; $i++) {
    $chain = jalaliAddMonths($chain, 1, 31);   // لنگر همیشه ۳۱
    $seen[] = $j($chain);
}
$wantChain = ['1405/07/30', '1405/08/30', '1405/09/30', '1405/10/30',
              '1405/11/30', '1405/12/29', '1406/01/31'];
$bad = [];
foreach ($wantChain as $i => $w) { if (($seen[$i] ?? '') !== $w) { $bad[] = "گام " . ($i + 1) . ": {$seen[$i]} (باید {$w})"; } }
T::bulk(7, $bad, '⛔ در زنجیره‌ی ۷ گامه، ۳۱ بعد از ماه‌های کوتاه برمی‌گردد');

// همان زنجیره برای ۳۰ اسفند: بعد از افتادن به ۲۹، باید در کبیسه‌ی بعدی
// دوباره ۳۰ شود.
$chain = $g(1403, 12, 30);
for ($i = 0; $i < 5; $i++) { $chain = jalaliAddMonths($chain, 12, 30); }
T::same('1408/12/30', $j($chain),
    '⛔ زنجیره‌ی سالانه هم لنگرِ ۳۰ اسفند را نگه می‌دارد');

// ---------------------------------------------------------------
T::group('۳۰ بهمن + ۱ ماه در سالِ غیرکبیسه');

// ۱۴۰۴ کبیسه نیست → اسفندش ۲۹ روز است، پس ۳۰ بهمن باید ۲۹ اسفند شود.
T::same('1404/12/29', $j(jalaliAddMonths($g(1404, 11, 30), 1, 30)),
    '۳۰ بهمن ۱۴۰۴ + ۱ ماه = ۲۹ اسفند (سالِ غیرکبیسه)');

// و در سالِ کبیسه همان حرکت به ۳۰ اسفند می‌رسد.
T::same('1403/12/30', $j(jalaliAddMonths($g(1403, 11, 30), 1, 30)),
    '۳۰ بهمن ۱۴۰۳ + ۱ ماه = ۳۰ اسفند (سالِ کبیسه)');

// ---------------------------------------------------------------
T::group('۳۰ اسفند + ۱۲ ماه');

// ۱۴۰۳ کبیسه است و ۱۴۰۴ نیست → ۳۰ اسفند ۱۴۰۳ + یک سال = ۲۹ اسفند ۱۴۰۴.
T::same('1404/12/29', $j(jalaliAddMonths($g(1403, 12, 30), 12, 30)),
    '⛔ ۳۰ اسفند ۱۴۰۳ + ۱۲ ماه = ۲۹ اسفند ۱۴۰۴');

// ⛔ و لنگر نگه داشته می‌شود: سالِ کبیسه‌ی **بعدی** ۱۴۰۸ است (نه ۱۴۰۷ —
//    با تقویمِ واقعی سنجیده شد، نه با فرضِ «هر چهار سال»). پس ۳۰ اسفندِ
//    ۱۴۰۳ + ۶۰ ماه باید دوباره ۳۰ اسفند بدهد. اگر روز از تاریخِ فعلی
//    برداشته می‌شد، بعد از اولین افتادن به ۲۹ برای همیشه ۲۹ می‌ماند.
$leapAgain = jalaliAddMonths($g(1403, 12, 30), 60, 30);
T::same('1408/12/30', $j($leapAgain),
    '⛔ در کبیسه‌ی بعدی دوباره ۳۰ اسفند — لنگر گم نشد');

// ---------------------------------------------------------------
T::group('N = ۴ در شش تکرارِ پشت‌سرهم');

// بیمه‌ی چهارماهه از ۱۵ فروردین ۱۴۰۵.
$cur  = $g(1405, 1, 15);
$want = ['1405/05/15', '1405/09/15', '1406/01/15',
         '1406/05/15', '1406/09/15', '1407/01/15'];
$got  = [];
for ($i = 0; $i < 6; $i++) {
    $cur = jalaliAddMonths($cur, 4, 15);
    $got[] = $j($cur);
}
$bad = [];
foreach ($want as $i => $w) { if (($got[$i] ?? '') !== $w) { $bad[] = "تکرار " . ($i + 1) . ": {$got[$i]} (باید {$w})"; } }
T::bulk(6, $bad, '⛔ N=۴ در شش تکرار دقیق می‌ماند و سال درست می‌چرخد');

// همان با N=۳ و N=۶ و N=۱۲ — هر N باید کار کند، نه فقط ۱ و ۱۲.
$bad = [];
foreach ([2, 3, 4, 6, 12] as $n) {
    $d = $g(1405, 1, 10);
    for ($i = 0; $i < 3; $i++) { $d = jalaliAddMonths($d, $n, 10); }
    // بعد از سه تکرار باید دقیقاً ۳n ماه جلو رفته باشد.
    $expect = jalaliAddMonths($g(1405, 1, 10), 3 * $n, 10);
    if ($d !== $expect) { $bad[] = "N={$n}: " . $j($d) . ' ≠ ' . $j($expect); }
}
T::bulk(5, $bad, 'سه تکرارِ N با یک پرشِ ۳N برابر است');

// ---------------------------------------------------------------
T::group('قانونِ «آخرین روزِ ماه»');

// با last_day_of_month روزِ لنگر بی‌اثر است و همیشه آخرِ ماه می‌نشیند.
T::same('1405/07/30', $j(jalaliAddMonths($g(1405, 6, 15), 1, 15, 'last_day_of_month')),
    '۱۵ شهریور + ۱ ماه با «آخر ماه» = ۳۰ مهر');
T::same('1404/12/29', $j(jalaliAddMonths($g(1404, 11, 5), 1, 5, 'last_day_of_month')),
    'و در اسفندِ غیرکبیسه = ۲۹ اسفند');

// ---------------------------------------------------------------
T::group('Schedule::nextDue — قانون به تاریخ');

T::same(null, Schedule::nextDue(['recurrence_type' => 'once'], $g(1405, 1, 1)),
    'یادآورِ یک‌باره تاریخِ بعدی ندارد');

T::same('1405/04/15', $j(Schedule::nextDue(
    ['recurrence_type' => 'every_n_months', 'recurrence_n' => 3, 'anchor_day' => 15],
    $g(1405, 1, 15))), 'هر ۳ ماه از ۱۵ فروردین → ۱۵ تیر');

T::same('1406/01/15', $j(Schedule::nextDue(
    ['recurrence_type' => 'yearly', 'recurrence_n' => 1, 'anchor_day' => 15],
    $g(1405, 1, 15))), 'سالانه → همان روز، سالِ بعد');

T::same('1407/01/15', $j(Schedule::nextDue(
    ['recurrence_type' => 'yearly', 'recurrence_n' => 2, 'anchor_day' => 15],
    $g(1405, 1, 15))), 'سالانه با N=۲ → دو سال بعد');

// ---------------------------------------------------------------
T::group('پله‌های اعلان');

T::same([1, 0], Schedule::stepsOf(['notify_days_before' => '[1]']),
    'پله‌ی پیش‌فرض به‌علاوه‌ی روزِ سررسید');
T::same([7, 3, 1, 0], Schedule::stepsOf(['notify_days_before' => '[7,3,1]']),
    'سه پله، از دور به نزدیک');
T::same([0], Schedule::stepsOf(['notify_days_before' => '[]']),
    '⛔ حتی با فهرستِ خالی، روزِ سررسید اعلان دارد');
// ⚠ JSON خراب به **پیش‌فرضِ ستون** برمی‌گردد ([1])، نه به فهرستِ خالی:
//   یادآوری که فقط روزِ خودِ سررسید خبر بدهد عملاً دیر است.
T::same([1, 0], Schedule::stepsOf(['notify_days_before' => 'خراب']),
    'JSON خراب به پیش‌فرض برمی‌گردد، نه به هیچ');
T::same([3, 0], Schedule::stepsOf(['notify_days_before' => '[3,99]']),
    'پله‌ی خارج از فهرستِ مجاز کنار گذاشته می‌شود');

// ---------------------------------------------------------------
// رفتارِ واقعی روی دیتابیس: اتصالِ چهار بخش و ساختِ سررسیدها.
if (!Schedule::available()) {
    T::group('اتصالِ بخش‌ها');
    T::skip('تست اتصال', 'migration_schedule.sql هنوز اجرا نشده');
    exit(T::report());
}

$pdo = Database::getConnection();

/**
 * ⚠ کاربرِ آزمایشی را با همه‌ی ردیف‌هایش پاک می‌کند.
 *
 *   `cheques.user_id` فقط ON UPDATE CASCADE دارد نه ON DELETE، پس حذفِ
 *   مستقیمِ کاربر با خطای کلیدِ خارجی می‌افتد. و اگر اجرای قبلی وسطِ
 *   کار مرده باشد، کاربرِ جامانده **ساختِ دوباره را هم می‌شکند** — پس
 *   همین تابع هم در آماده‌سازی و هم در پاک‌سازی صدا زده می‌شود.
 */
$purge = function (string $username) use ($pdo): void {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    foreach ($st->fetchAll() as $row) {
        $id = (int)$row['id'];
        foreach (['reminder_notifications', 'reminder_occurrences', 'reminders',
                  'cheques', 'debts', 'recurring_transactions'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (Throwable $e) { /* جدول نیست */ }
        }
        try { $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]); }
        catch (Throwable $e) { /* ignore */ }
    }
};

$purge('__sched__');
$purge('__sched2__');

$mkUser = function (string $u, string $name) use ($pdo): int {
    $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active)
                   VALUES (:n, :u, :p, "user", 1)')
        ->execute(['n' => $name, 'u' => $u, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
};
$uid   = $mkUser('__sched__',  'تست سررسید');
$other = $mkUser('__sched2__', 'تست دوم');

try {
    $today = today();
    $soon  = date('Y-m-d', strtotime($today . ' +3 day'));
    $late  = date('Y-m-d', strtotime($today . ' -5 day'));

    // ---------------------------------------------------------------
    T::group('اتصالِ چک و طلب/بدهی به هسته');

    $pdo->prepare("INSERT INTO cheques (user_id,direction,counterparty_name,amount,due_date,status,is_settled)
                   VALUES (:u,'received','فروشنده',5000000,:d,'pending',0)")
        ->execute(['u' => $uid, 'd' => $soon]);
    $pdo->prepare("INSERT INTO debts (user_id,direction,counterparty_name,amount,paid_amount,entry_date,due_date,is_settled)
                   VALUES (:u,'payable','همکار',3000000,1000000,:e,:d,0)")
        ->execute(['u' => $uid, 'e' => $late, 'd' => $late]);

    T::ok(syncScheduleRules($uid) >= 2, 'برای چک و بدهی قانون ساخته شد');
    Schedule::materializeAll($uid, $today);

    $st = $pdo->prepare("SELECT r.source_type, r.amount, o.due_date, o.status
                           FROM reminder_occurrences o
                           JOIN reminders r ON r.id = o.reminder_id
                          WHERE o.user_id = :u ORDER BY o.due_date");
    $st->execute(['u' => $uid]);
    $occ = $st->fetchAll();
    T::same(2, count($occ), 'دو سررسید ساخته شد — یکی چک، یکی بدهی');

    $types = array_column($occ, 'source_type');
    T::ok(in_array('cheque', $types, true) && in_array('debt', $types, true),
        'هر دو نوع در فهرست هستند');

    // ⛔ مبلغِ بدهی باید **باقیمانده** باشد نه مبلغِ کل: کاربری که نصفِ
    //    بدهی را داده نباید در سررسیدها عددِ اولیه را ببیند.
    foreach ($occ as $o) {
        if ($o['source_type'] === 'debt') {
            T::same(2000000, (int)$o['amount'], '⛔ مبلغِ بدهی باقیمانده است، نه کل');
        }
    }
    T::same(1, count(array_filter($occ, fn($o) => $o['status'] === 'overdue')),
        'سررسیدِ گذشته وضعیتِ overdue گرفت');

    // ---------------------------------------------------------------
    T::group('⛔ اجرای دوباره سررسیدِ تازه نمی‌سازد');

    // خرابیِ واقعی: هر اجرا یک سررسیدِ آینده‌ی تازه اضافه می‌کرد، پس
    // کرونِ ساعتی روزی ۲۴ ردیف انبوه می‌ساخت — خلافِ «از قبل انبوه
    // نکن». با اجرای واقعی پیدا شد، نه با نگاه.
    // ⛔ و حتماً با یک قانونِ **تکرارشونده**: نشتی فقط آنجا رخ می‌دهد،
    //    چون قانونِ یک‌باره بعد از اولین سررسید `nextDue()` ندارد و
    //    حلقه خودش می‌ایستد. نسخه‌ی اولِ همین تست فقط قانونِ یک‌باره
    //    داشت و نسبت به نشتی **کور** بود — جهش نشانش داد.
    $pdo->prepare("INSERT INTO reminders
                    (user_id, source_type, title, remind_date, recurrence_type, recurrence_n,
                     anchor_day, status)
                   VALUES (:u, 'custom', 'بیمه سالانه', :d, 'yearly', 1, :a, 'active')")
        ->execute(['u' => $uid, 'd' => $soon, 'a' => jalaliDayOf($soon)]);
    Schedule::materializeAll($uid, $today);

    $before = (int)$pdo->query("SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = $uid")->fetchColumn();
    for ($i = 0; $i < 5; $i++) { syncScheduleRules($uid); Schedule::materializeAll($uid, $today); }
    $after = (int)$pdo->query("SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = $uid")->fetchColumn();
    T::same($before, $after, '⛔ پنج اجرای پشت‌سرهم هیچ ردیفی اضافه نمی‌کند');

    // ---------------------------------------------------------------
    T::group('⛔ جداسازی کاربران');

    syncScheduleRules($other);
    Schedule::materializeAll($other, $today);
    T::same(0, (int)$pdo->query("SELECT COUNT(*) FROM reminder_occurrences WHERE user_id = $other")->fetchColumn(),
        '⛔ کاربرِ دوم هیچ سررسیدی از کاربرِ اول نمی‌گیرد');

    $someId = (int)$pdo->query("SELECT id FROM reminder_occurrences WHERE user_id = $uid LIMIT 1")->fetchColumn();
    T::same(false, Schedule::close($other, $someId, 'done'),
        '⛔ کاربرِ دوم نمی‌تواند سررسیدِ کاربرِ اول را ببندد');
    T::same(false, Schedule::snooze($other, $someId, $soon),
        '⛔ و نمی‌تواند تعویقش کند');

    // ---------------------------------------------------------------
    T::group('تعویق و تسویه');

    $oid = (int)$pdo->query("SELECT id FROM reminder_occurrences
                              WHERE user_id = $uid AND status = 'overdue' LIMIT 1")->fetchColumn();
    $newDate = date('Y-m-d', strtotime($today . ' +7 day'));
    T::ok(Schedule::snooze($uid, $oid, $newDate), 'تعویق انجام شد');

    $chk = $pdo->prepare('SELECT due_date, status FROM reminder_occurrences WHERE id = :i');
    $chk->execute(['i' => $oid]);
    $row = $chk->fetch();
    T::same($newDate, $row['due_date'], 'تاریخ جلو رفت');
    T::same('pending', $row['status'], 'و از حالتِ عقب‌افتاده درآمد');

    T::ok(Schedule::close($uid, $oid, 'done'), 'بستنِ سررسید انجام شد');
    $chk->execute(['i' => $oid]);
    T::same('done', $chk->fetch()['status'], 'وضعیتش done شد');

    // ---------------------------------------------------------------
    T::group('⛔ چکِ پاس‌شده از فهرست بیرون می‌رود');

    $pdo->prepare("UPDATE cheques SET status = 'cleared', is_settled = 1 WHERE user_id = :u")
        ->execute(['u' => $uid]);
    syncScheduleRules($uid);
    T::same(1, (int)$pdo->query("SELECT COUNT(*) FROM reminders
                                  WHERE user_id = $uid AND source_type = 'cheque'
                                    AND status = 'finished'")->fetchColumn(),
        '⛔ قانونِ چکِ پاس‌شده بسته شد، نه اینکه تا ابد بماند');
} finally {
    $purge('__sched__');
    $purge('__sched2__');
}

exit(T::report());
