<?php
/**
 * push-send.php — اعلان‌های تازه را به گوشی‌ها می‌فرستد (هر دقیقه با cron).
 *
 * ─── رعایت قانون جداسازی ───────────────────────────────────────────
 * فقط `/etc/cron.d/hesab-push` را می‌نویسد (با --install-cron)، و فقط به
 * دیتابیسِ همین اپ و `var/push/` دست می‌زند. درخواستِ بیرونی فقط به
 * سرویس‌های پوشِ مرورگرهاست (`Push::HOSTS`).
 * ───────────────────────────────────────────────────────────────────
 *
 * اجرا:
 *     php deploy/push-send.php                  # فقط وضعیت
 *     php deploy/push-send.php --run            # فرستادنِ واقعی (کارِ cron)
 *     sudo php deploy/push-send.php --install-cron
 *
 * ⛔ دو کار در هر دور:
 *    ۱. `Push::dailyGenerate()` — روزی یک بار (از ساعتِ ۸) سررسیدها و
 *       یادآورهای امروزِ دارندگانِ اشتراک ساخته می‌شوند، حتی اگر اپ را باز
 *       نکرده باشند. بدونِ آن، «چکِ امروز» فقط وقتی اعلان می‌شد که کاربر
 *       خودش اپ را باز کند.
 *    ۲. `Push::flushPending()` — هر اعلانِ تازه‌ی نخوانده (پاسخِ پشتیبانی،
 *       سود فروشگاه، …) ظرفِ یک دقیقه روی گوشی.
 *
 * ⚠ کلیدِ VAPID را **نمی‌سازد** (با root اجرا می‌شود و فایل مالِ root
 *   می‌شد — دامِ `var/sessions`). بی‌کلید یعنی هنوز کسی اعلان را روشن
 *   نکرده، پس چیزی هم برای فرستادن نیست؛ نشانه با این حال زده می‌شود تا
 *   پنلِ مدیر «کهنه» نشان ندهد.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/push.php';
require_once __DIR__ . '/../includes/cron_health.php';

$args   = array_slice($argv, 1);
$run    = in_array('--run', $args, true);
$appDir = dirname(__DIR__);

if (in_array('--install-cron', $args, true)) {
    $line = "* * * * * root cd {$appDir} && /usr/bin/php deploy/push-send.php --run >> /var/log/hesab-push.log 2>&1\n";
    file_put_contents('/etc/cron.d/hesab-push', "# اعلانِ گوشی (Web Push) — هر دقیقه\n" . $line);
    @chmod('/etc/cron.d/hesab-push', 0644);
    echo "زمان‌بندیِ هر دقیقه نصب شد: /etc/cron.d/hesab-push\n";
    echo "برای لغو:  sudo rm /etc/cron.d/hesab-push\n";
    exit(0);
}

if (!Push::available()) {
    if ($run) { CronHealth::beat('push'); exit(0); }
    echo "اعلانِ گوشی در دسترس نیست: یا migration_push اجرا نشده، یا PHP افزونه‌ی openssl ِ کامل ندارد.\n";
    exit(0);
}

if (!$run) {
    $pdo = Database::getConnection();
    $subs  = (int)$pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    $users = (int)$pdo->query('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions')->fetchColumn();
    echo 'کلیدِ VAPID:   ' . (Push::vapid(false) ? 'ساخته شده' : 'هنوز ساخته نشده (با اولین «روشن کردن» در پروفایل ساخته می‌شود)') . "\n";
    echo "اشتراک‌ها:      {$subs} دستگاه از {$users} کاربر\n";
    echo "\nبرای اجرای واقعی:  php deploy/push-send.php --run\n";
    exit(0);
}

try {
    $made = Push::dailyGenerate();
    $r    = Push::flushPending();
} catch (Throwable $e) {
    // ⛔ نشانه زده **نمی‌شود** — نشانه‌ای که روی خرابی هم بخورد دروغ می‌گوید.
    Log::error('push.cron', $e);
    fwrite(STDERR, date('Y-m-d H:i:s') . ' — ' . $e->getMessage() . "\n");
    exit(1);
}

CronHealth::beat('push');

// ⚠ مسیرِ موفق ساکت است مگر واقعاً چیزی رفته باشد (هر دقیقه اجرا می‌شود).
if ($made > 0 || $r['sent'] > 0 || $r['gone'] > 0 || $r['failed'] > 0) {
    Log::info('push.flushed', ['generated' => $made] + $r);
    echo date('Y-m-d H:i:s') . " — ساخته {$made}، فرستاده {$r['sent']}، رفته {$r['gone']}، ناموفق {$r['failed']}\n";
}
exit(0);
