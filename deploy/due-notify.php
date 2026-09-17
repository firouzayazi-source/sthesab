<?php
/**
 * کرونِ ساعتیِ اعلانِ سررسید.
 *
 * ⛔ ایدمپوتنت است و درستی‌اش از **کلیدِ یکتای** جدولِ
 *    `reminder_notifications` می‌آید، نه از زمان‌بندی. اجرای دوباره‌ی
 *    همان ساعت هیچ اعلانِ تکراری نمی‌سازد — و این مهم است، چون یک
 *    اشتباهِ کوچک در cron سریع‌ترین راهِ این است که کاربر اعلان‌ها را
 *    خاموش کند.
 *
 * ⛔ هر ساعت اجرا می‌شود ولی فقط برای کاربرانی کار می‌کند که ساعتِ
 *    یادآوریِ خودشان همین ساعت باشد. تایم‌زون از `APP_TIMEZONE` می‌آید
 *    (Asia/Tehran)، نه از ساعتِ سیستم.
 *
 *     php deploy/due-notify.php                 فقط گزارش
 *     php deploy/due-notify.php --send          واقعاً بساز
 *     php deploy/due-notify.php --hour 9        ساعتِ دلخواه (برای آزمودن)
 *     php deploy/due-notify.php --install-cron  زمان‌بندی ساعتی
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args   = array_slice($argv, 1);
$send   = in_array('--send', $args, true);
$appDir = dirname(__DIR__);

if (in_array('--install-cron', $args, true)) {
    $line = "0 * * * * root cd {$appDir} && /usr/bin/php deploy/due-notify.php --send >> /var/log/hesab-due.log 2>&1\n";
    file_put_contents('/etc/cron.d/hesab-due', "# یادآوری سررسید — ساعتی\n" . $line);
    @chmod('/etc/cron.d/hesab-due', 0644);
    echo "زمان‌بندی ساعتی نصب شد: /etc/cron.d/hesab-due\n";
    exit(0);
}

$hourArg = null;
foreach ($args as $i => $a) {
    if ($a === '--hour' && isset($args[$i + 1])) { $hourArg = (int)$args[$i + 1]; }
}
$hour = $hourArg !== null ? $hourArg : (int)date('G');

$pdo = Database::getConnection();
if (!Schedule::available()) { exit("جدولِ سررسیدها نیست — migration را اعمال کنید.\n"); }

$today = today();
$made  = 0;
$users = 0;

// ⚠ فقط کاربرانی که ساعتشان همین ساعت است. نبودِ ردیف یعنی پیش‌فرضِ ۹.
$rows = $pdo->query('SELECT id FROM users WHERE is_active = 1')->fetchAll();

foreach ($rows as $u) {
    $uid = (int)$u['id'];
    if (Schedule::userHour($uid) !== $hour) { continue; }
    $users++;

    syncScheduleRules($uid);
    Schedule::materializeAll($uid, $today);

    $st = $pdo->prepare("
        SELECT o.id, o.due_date, o.status, r.title, r.amount, r.source_type,
               r.notify_days_before
          FROM reminder_occurrences o
          JOIN reminders r ON r.id = o.reminder_id AND r.user_id = o.user_id
         WHERE o.user_id = :u AND o.status IN ('pending','overdue')
           AND o.due_date <= DATE_ADD(:t, INTERVAL 7 DAY)
    ");
    $st->execute(['u' => $uid, 't' => $today]);

    foreach ($st->fetchAll() as $o) {
        $daysLeft = (int)floor((strtotime($o['due_date']) - strtotime($today)) / 86400);

        // پله‌ی مربوط به امروز: ۷/۳/۱ پیش از سررسید، ۰ روزِ سررسید،
        // و ‎-۱ یک اعلانِ **یک‌باره** بعد از عقب افتادن.
        $step = null;
        foreach (Schedule::stepsOf($o) as $s) {
            if ($daysLeft === $s) { $step = $s; break; }
        }
        if ($step === null && $daysLeft < 0) { $step = -1; }
        if ($step === null) { continue; }

        // ⛔ نگهبانِ تکرار: کلیدِ یکتا کار را می‌کند، نه شرطِ زمانی.
        $g = $pdo->prepare('INSERT IGNORE INTO reminder_notifications
                            (occurrence_id, user_id, days_before) VALUES (:o, :u, :d)');
        $g->execute(['o' => (int)$o['id'], 'u' => $uid, 'd' => $step]);
        if ($g->rowCount() === 0) { continue; }

        $when = $step > 0 ? toPersianDigits((string)$step) . ' روز مانده'
              : ($step === 0 ? 'سررسید امروز' : 'سررسید گذشته');
        $body = $when;
        if (!empty($o['amount'])) { $body .= ' · ' . formatMoney((int)$o['amount']); }

        if ($send) {
            Notify::push($uid, 'due', (string)$o['title'], $body, 'due.php',
                'occ:' . (int)$o['id'] . ':' . $step);
        }
        $made++;
    }
}

printf("ساعت %s — کاربرانِ این ساعت: %d — اعلانِ %s: %d\n",
    str_pad((string)$hour, 2, '0', STR_PAD_LEFT), $users, $send ? 'ساخته‌شده' : 'قابلِ ساخت', $made);
if (!$send) { echo "حالت نمایشی — چیزی ساخته نشد. برای اجرا: --send\n"; }

// همان قاعده‌ی `reminders.php`: اجرای نمایشی نشانه نمی‌گذارد.
if ($send) {
    CronHealth::beat('due-notify');
    Log::info('job.done', ['job' => 'due-notify', 'users' => $users, 'made' => $made, 'ms' => Log::elapsedMs()]);
}
