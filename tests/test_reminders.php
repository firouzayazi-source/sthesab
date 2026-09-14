<?php
/**
 * تستِ یادآوریِ سررسید.
 *
 * چیزهایی که اگر بشکنند **بی‌صدا** خراب می‌شوند، چون این کد با cron
 * اجرا می‌شود و خروجی‌اش را کسی نمی‌بیند:
 *
 *  ۱. پیش‌فرضِ روشن — کاربری که ردیفی در notification_prefs ندارد باید
 *     یادآوری بگیرد، وگرنه این قابلیت برای همه‌ی کاربرانِ موجود خاموش
 *     می‌ماند و هیچ‌کس هرگز روشنش نمی‌کند.
 *  ۲. متنِ ایمیل — هم HTML و هم نسخه‌ی متنی، با مبلغ و تاریخِ شمسی.
 *  ۳. جدا شدنِ سررسیدگذشته از پیشِ رو.
 *
 * برای اجرا به دیتابیس نیاز دارد؛ اگر نبود، رد می‌شود نه شکست.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('یادآوری سررسید');
    T::blocked('تست یادآوری', 'config/config.php وجود ندارد');
    exit(T::report());
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('یادآوری سررسید');
    T::blocked('تست یادآوری', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

if (!tableExists('notification_prefs')) {
    T::group('یادآوری سررسید');
    T::skip('تست یادآوری', 'migration_reminders اجرا نشده');
    exit(T::report());
}

// ---------------------------------------------------------------
$TESTU = '__test_reminder_user';

$cleanup = function () use ($pdo, $TESTU) {
    $st = $pdo->prepare('SELECT id FROM users WHERE username = :u');
    $st->execute(['u' => $TESTU]);
    if ($id = $st->fetchColumn()) {
        foreach (['notification_prefs', 'debts', 'cheques', 'transactions', 'wallets'] as $t) {
            try { $pdo->prepare("DELETE FROM `$t` WHERE user_id = :u")->execute(['u' => $id]); }
            catch (PDOException $e) { /* جدول شاید نباشد */ }
        }
    }
    $pdo->prepare('DELETE FROM users WHERE username = :u')->execute(['u' => $TESTU]);
};
$cleanup();

$pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, 'کاربر تست یادآوری', 'user')"
)->execute(['u' => $TESTU, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
ensureDefaultWallet($userId);

// ---------------------------------------------------------------
T::group('پیش‌فرضِ یادآوری روشن است');

// ⛔ اگر پیش‌فرض خاموش بود، این قابلیت برای همه‌ی کاربرانِ موجود
//    خاموش می‌ماند — و کاربری که نمی‌داند وجود دارد هرگز روشنش
//    نمی‌کند، بعد چکش برگشت می‌خورد. این تنها تنظیمی است که ارزشش در
//    نبودنش دیده نمی‌شود.
$st = $pdo->prepare('SELECT COUNT(*) FROM notification_prefs WHERE user_id = :u');
$st->execute(['u' => $userId]);
T::same(0, (int)$st->fetchColumn(), 'کاربرِ تازه هیچ ردیفِ تنظیمی ندارد');

$p = reminderPrefs($userId);
T::ok($p['email_on'], 'بدون ردیفِ تنظیم، یادآوری روشن است');
T::same(3, $p['days_before'], 'پیش‌فرضِ «چند روز قبل» سه روز است');

// و خاموش کردن باید واقعاً بماند
$pdo->prepare(
    'INSERT INTO notification_prefs (user_id, email_on, days_before) VALUES (:u, 0, 7)'
)->execute(['u' => $userId]);
$p = reminderPrefs($userId);
T::ok(!$p['email_on'], 'خاموش کردن ذخیره می‌شود');
T::same(7, $p['days_before'], 'بازه‌ی انتخابی ذخیره می‌شود');

// فهرستِ مجاز فقط یک جا تعریف شده — فهرستِ دوم یعنی گزینه‌ای که کاربر
// می‌بیند هنگام ذخیره بی‌صدا به پیش‌فرض برمی‌گردد.
T::ok(in_array(3, REMINDER_DAYS, true), 'پیش‌فرض داخل فهرستِ مجاز است');
T::ok(count(REMINDER_DAYS) === count(array_unique(REMINDER_DAYS)),
    'فهرستِ بازه‌ها تکراری ندارد');

// ---------------------------------------------------------------
T::group('جدا شدنِ سررسیدگذشته از پیشِ رو');

$mkDebt = function (string $due, int $amount, string $who) use ($pdo, $userId) {
    $pdo->prepare(
        "INSERT INTO debts (user_id, direction, counterparty_name, amount, paid_amount,
                            entry_date, due_date, is_settled)
         VALUES (:u, 'payable', :w, :a, 0, :e, :d, 0)"
    )->execute(['u' => $userId, 'w' => $who, 'a' => $amount,
                'e' => date('Y-m-d', strtotime('-120 days')), 'd' => $due]);
};

$mkDebt(date('Y-m-d', strtotime('-5 days')),  4000000, 'رضا');   // گذشته
$mkDebt(date('Y-m-d', strtotime('+2 days')),  1500000, 'سارا');  // پیشِ رو
$mkDebt(date('Y-m-d', strtotime('+40 days')), 9000000, 'حسن');   // بیرونِ بازه

$today  = today();
$events = financialEvents($userId, date('Y-m-d', strtotime('-90 days')),
                                   date('Y-m-d', strtotime('+3 days')));
$overdue = array_values(array_filter($events, fn($e) => $e['date'] < $today));
$soon    = array_values(array_filter($events, fn($e) => $e['date'] >= $today));

T::same(1, count($overdue), 'یک مورد سررسیدگذشته');
T::same(1, count($soon), 'یک مورد تا سه روز آینده');
T::ok(!array_filter($events, fn($e) => (int)$e['amount'] === 9000000),
    'موردِ ۴۰ روز بعد در بازه‌ی سه‌روزه نمی‌آید');

// ---------------------------------------------------------------
T::group('متنِ ایمیل — هم HTML و هم متنِ ساده');

[$html, $text] = reminderEmailBody('کاربر تست', $overdue, $soon, 3);

// ⛔ نسخه‌ی متنی اختیاری نیست: بعضی کلاینت‌ها HTML نشان نمی‌دهند و
//    فیلترهای اسپم به ایمیلِ فقط-HTML سخت‌گیرترند.
T::ok($text !== '', 'نسخه‌ی متنی خالی نیست');
T::ok($html !== '', 'نسخه‌ی HTML خالی نیست');

foreach ([['HTML', $html], ['متن', $text]] as [$label, $body]) {
    T::ok(str_contains($body, 'رضا'), "$label نامِ طرفِ سررسیدگذشته را دارد");
    T::ok(str_contains($body, 'سارا'), "$label نامِ طرفِ پیشِ رو را دارد");
    T::ok(str_contains($body, formatMoney(4000000)), "$label مبلغ را با قالبِ فارسی دارد");
    T::ok(str_contains($body, toJalali($overdue[0]['date'])), "$label تاریخِ شمسی دارد");
    T::ok(!str_contains($body, '9000000') && !str_contains($body, formatMoney(9000000)),
        "$label موردِ بیرونِ بازه را ندارد");
}

// راهِ خاموش کردن باید در خودِ ایمیل باشد — وگرنه کاربر آدرس را اسپم
// علامت می‌زند و بعد هیچ ایمیلی از این دامنه نمی‌رسد.
T::ok(str_contains($text, 'خاموش'), 'متن راهِ خاموش کردن را می‌گوید');
T::ok(str_contains($html, 'خاموش'), 'HTML راهِ خاموش کردن را می‌گوید');

// لینک باید از APP_URL بیاید نه از HTTP_HOST — همان قاعده‌ی لینکِ
// بازیابیِ رمز، وگرنه با Host جعلی می‌شد کاربر را به سایتِ مهاجم برد.
T::ok(str_contains($html, appBaseUrl()), 'لینک از APP_URL ساخته می‌شود');

// وقتی چیزی سررسیدگذشته نیست، بخشش نباید بیاید
[$h2, $t2] = reminderEmailBody('کاربر تست', [], $soon, 3);
T::ok(!str_contains($t2, 'سررسید گذشته'), 'بدون موردِ گذشته، آن بخش نمی‌آید');
T::ok(str_contains($t2, 'سارا'), 'بخشِ پیشِ رو همچنان می‌آید');

// ---------------------------------------------------------------
T::group('نگهبانِ «یک ایمیل در روز»');

// ⛔ بدون این، یک اشتباهِ زمان‌بندی (cron دو بار، یا اجرای دستی) به هر
//    کاربر چند ایمیل می‌فرستاد — سریع‌ترین راهِ اینکه کاربر یادآوری را
//    خاموش کند یا آدرس را اسپم علامت بزند.
$pdo->prepare('UPDATE notification_prefs SET email_on = 1, last_sent_on = :d WHERE user_id = :u')
    ->execute(['u' => $userId, 'd' => $today]);
$st = $pdo->prepare('SELECT last_sent_on FROM notification_prefs WHERE user_id = :u');
$st->execute(['u' => $userId]);
T::same($today, $st->fetchColumn(), 'روزِ آخرین ارسال ثبت می‌شود');

// اسکریپت باید همین ستون را ببیند و رد کند
$script = file_get_contents(__DIR__ . '/../deploy/reminders.php');
T::ok(str_contains($script, "last_sent_on'] === \$today"),
    'اسکریپت روزِ آخرین ارسال را می‌سنجد');
T::ok(str_contains($script, "PHP_SAPI !== 'cli'"),
    'اسکریپت نگهبانِ خط فرمان دارد');

// ---------------------------------------------------------------
$cleanup();
exit(T::report());
