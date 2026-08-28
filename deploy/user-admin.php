<?php
/**
 * user-admin.php — در پشتی برای وقتی که هیچ‌کس نمی‌تواند وارد شود.
 *
 * چرا لازم است: بازیابی با ایمیل هم می‌تواند از کار بیفتد — ایمیل نرسد،
 * دامنه‌ی فرستنده اسپم شود، یا اصلاً ایمیلی برای کاربر ثبت نشده باشد.
 * این ابزار به هیچ‌کدام وابسته نیست و مستقیم با دیتابیس کار می‌کند.
 *
 * ⚠️ فقط از خط فرمان اجرا می‌شود. اگر کسی از راه وب صدایش بزند، بلافاصله
 *    می‌میرد — وگرنه هر کسی می‌توانست رمز مدیر را عوض کند.
 *
 * استفاده (نمونه‌ها با مقدار واقعی — نه placeholder داخل < >، چون bash
 * آن را تغییرمسیر ورودی می‌فهمد و فرمان با syntax error می‌میرد):
 *   php deploy/user-admin.php --list
 *   php deploy/user-admin.php --reset ali
 *   php deploy/user-admin.php --reset ali --password 'MyNewPass123'
 *   php deploy/user-admin.php --activate ali
 *   php deploy/user-admin.php --set-email ali ali@gmail.com
 *   php deploy/user-admin.php --test-mail you@gmail.com
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../includes/db.php';

$argvIn = $argv;
array_shift($argvIn);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): void { fwrite(STDERR, "\033[0;31m$s\033[0m\n"); exit(1); }
function ok(string $s): void { out("\033[0;32m$s\033[0m"); }
function red(string $s): void { out("\033[0;31m$s\033[0m"); }
function info(string $s): void { out("\033[0;36m$s\033[0m"); }
function usage(): void
{
    $me = 'php deploy/user-admin.php';
    out("استفاده — «ali» و ایمیل نمونه را با مقدار واقعی عوض کنید:");
    out("  $me --list                             فهرست کاربران");
    out("  $me --reset ali                        رمز تازه (خودش می‌سازد)");
    out("  $me --reset ali --password 'رمزدلخواه'  رمز دلخواه");
    out("  $me --activate ali                     فعال کردن کاربر غیرفعال");
    out("  $me --set-email ali ali@gmail.com      ثبت ایمیل برای بازیابی");
    out("  $me --test-mail you@gmail.com          آزمایش تنظیمات ایمیل");
    out("");
    out("نشانه‌های < > را در فرمان ننویسید — bash آن‌ها را تغییرمسیر فایل می‌فهمد");
    out("و با «syntax error near unexpected token» متوقف می‌شود.");
    exit(0);
}

if (!$argvIn || in_array($argvIn[0], ['-h', '--help'], true)) { usage(); }

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    fail('اتصال به دیتابیس برقرار نشد: ' . $e->getMessage());
}

$hasEmailColumn = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email'"
)->fetchColumn();

$cmd = $argvIn[0];

/** کاربر را با نام کاربری پیدا کن، وگرنه با پیام روشن بمیر */
$findUser = function (string $username) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $row = $st->fetch();
    if (!$row) {
        fail("کاربری با نام «$username» پیدا نشد. با --list فهرست را ببینید.");
    }
    return $row;
};

// ---------------------------------------------------------------
if ($cmd === '--list') {
    $cols = $hasEmailColumn ? 'id, username, full_name, role, is_active, email' : 'id, username, full_name, role, is_active';
    $rows = $pdo->query("SELECT $cols FROM users ORDER BY id")->fetchAll();
    if (!$rows) { out('هیچ کاربری در دیتابیس نیست.'); exit(0); }

    out('');
    printf("  %-4s %-16s %-22s %-7s %-8s %s\n", 'id', 'نام کاربری', 'نام', 'نقش', 'وضعیت', $hasEmailColumn ? 'ایمیل' : '');
    out('  ' . str_repeat('─', 76));
    foreach ($rows as $r) {
        printf("  %-4s %-16s %-22s %-7s %-8s %s\n",
            $r['id'], $r['username'], $r['full_name'], $r['role'],
            $r['is_active'] ? 'فعال' : 'غیرفعال',
            $hasEmailColumn ? ($r['email'] ?? '—') : '');
    }
    out('');
    if (!$hasEmailColumn) {
        out("  ستون ایمیل هنوز ساخته نشده — migration را اجرا کنید:");
        out("      bash deploy/migrate.sh --apply");
        out('');
    }
    exit(0);
}

// ---------------------------------------------------------------
if ($cmd === '--reset') {
    $username = $argvIn[1] ?? fail('نام کاربری را بدهید. نمونه:  --reset ali');
    $user = $findUser($username);

    $i = array_search('--password', $argvIn, true);
    if ($i !== false) {
        $password = $argvIn[$i + 1] ?? fail('بعد از --password رمز را بنویسید.');
        if (mb_strlen($password) < 8) { fail('رمز باید حداقل ۸ کاراکتر باشد.'); }
        $generated = false;
    } else {
        // رمز خوانا: بدون کاراکترهایی که در خواندن اشتباه می‌شوند (0/O, 1/l/I)
        $alphabet = 'abcdefghjkmnpqrstuvwxyzACDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($n = 0; $n < 14; $n++) { $password .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $generated = true;
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $st->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);

        // دستگاه‌های مورد اعتماد باید باطل شوند: اگر کسی رمز را فراموش کرده،
        // ممکن است دلیلش دسترسی ناخواسته‌ی شخص دیگری باشد.
        $revoked = 0;
        try {
            $d = $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :id');
            $d->execute(['id' => $user['id']]);
            $revoked = $d->rowCount();
        } catch (PDOException $e) {
            // جدول هنوز ساخته نشده — مهم نیست
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('تغییر رمز انجام نشد: ' . $e->getMessage());
    }

    out('');
    ok("رمز کاربر «{$user['username']}» ({$user['full_name']}) عوض شد.");
    if ($generated) {
        out('');
        out("    رمز تازه:  \033[1m$password\033[0m");
        out('');
        out('    این رمز فقط همین یک بار نمایش داده می‌شود.');
        out('    بعد از ورود، از صفحه‌ی پروفایل عوضش کنید.');
    }
    if ($revoked > 0) {
        out("    $revoked دستگاه مورد اعتماد باطل شد — همه باید دوباره وارد شوند.");
    }
    if (!$user['is_active']) {
        out('');
        out("  \033[0;33m⚠  این کاربر غیرفعال است و با رمز تازه هم وارد نمی‌شود.\033[0m");
        out("     فعالش کنید:  php deploy/user-admin.php --activate {$user['username']}");
    }
    out('');
    exit(0);
}

// ---------------------------------------------------------------
if ($cmd === '--activate') {
    $username = $argvIn[1] ?? fail('نام کاربری را بدهید. نمونه:  --activate ali');
    $user = $findUser($username);
    if ($user['is_active']) { out("کاربر «$username» از قبل فعال است."); exit(0); }
    $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $user['id']]);
    ok("کاربر «$username» فعال شد.");
    exit(0);
}

// ---------------------------------------------------------------
if ($cmd === '--set-email') {
    if (!$hasEmailColumn) {
        fail("ستون ایمیل هنوز ساخته نشده. اول:  bash deploy/migrate.sh --apply");
    }
    $username = $argvIn[1] ?? fail('استفاده — نمونه:  --set-email ali ali@gmail.com');
    $email    = $argvIn[2] ?? fail('استفاده — نمونه:  --set-email ali ali@gmail.com');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fail("ایمیل معتبر نیست: $email"); }
    $user = $findUser($username);

    $dup = $pdo->prepare('SELECT username FROM users WHERE email = :e AND id <> :id');
    $dup->execute(['e' => $email, 'id' => $user['id']]);
    if ($other = $dup->fetchColumn()) {
        fail("این ایمیل برای کاربر «$other» ثبت شده است.");
    }

    $pdo->prepare('UPDATE users SET email = :e WHERE id = :id')
        ->execute(['e' => $email, 'id' => $user['id']]);
    ok("ایمیل «$email» برای کاربر «$username» ثبت شد.");
    out('حالا می‌تواند از صفحه‌ی ورود، «رمز را فراموش کرده‌ام» را بزند.');
    exit(0);
}

// ---------------------------------------------------------------
if ($cmd === '--test-mail') {
    require_once __DIR__ . '/../includes/mailer.php';

    $to = $argvIn[1] ?? fail('آدرس گیرنده را بدهید: --test-mail you@example.com');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { fail("آدرس معتبر نیست: $to"); }

    out('');
    out('  تنظیمات فعلی:');
    $show = function (string $const, bool $secret = false) {
        if (!defined($const)) { out(sprintf('    %-16s تعریف نشده', $const)); return; }
        $v = constant($const);
        if ($v === '' || $v === null) { out(sprintf('    %-16s (خالی)', $const)); return; }
        out(sprintf('    %-16s %s', $const, $secret ? str_repeat('•', 8) : (string)$v));
    };
    foreach (['MAIL_METHOD', 'MAIL_FROM', 'MAIL_FROM_NAME', 'APP_URL'] as $c) { $show($c); }
    if (defined('MAIL_METHOD') && MAIL_METHOD === 'smtp') {
        foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_EHLO'] as $c) { $show($c); }
        $show('SMTP_PASS', true);
    }
    out('');

    if (!Mailer::isConfigured()) {
        red('  ارسال ایمیل تنظیم نشده است.');
        out('');
        out('  در config/config.php حداقل این‌ها لازم است:');
        out("      define('MAIL_METHOD', 'smtp');   // یا 'mail'");
        out("      define('MAIL_FROM', 'no-reply@example.com');");
        out("      define('SMTP_HOST', 'mail.example.com');  // فقط برای smtp");
        out('');
        out('  نمونه‌ی کامل در config/config.example.php هست.');
        exit(1);
    }

    info("  در حال فرستادن ایمیل آزمایشی به $to ...");
    $t0 = microtime(true);
    $okSend = Mailer::send(
        $to,
        'آزمایش تنظیمات ایمیل — ' . (defined('APP_NAME') ? APP_NAME : 'دفتر مالی'),
        '<div style="font-family:Tahoma;direction:rtl;text-align:right">'
        . '<h3>ارسال ایمیل درست کار می‌کند ✅</h3>'
        . '<p>این پیام آزمایشی از سرور دفتر مالی فرستاده شده است.</p>'
        . '<p>حالا بازیابی رمز با ایمیل هم کار می‌کند.</p></div>'
    );
    $ms = (int)round((microtime(true) - $t0) * 1000);

    out('');
    if ($okSend) {
        ok("  ایمیل فرستاده شد ({$ms} میلی‌ثانیه).");
        out('  صندوق ورودی و پوشه‌ی هرزنامه را نگاه کنید.');
        out('  اگر نرسید، مشکل از تحویل است نه از تنظیمات — SPF و DKIM دامنه را بررسی کنید.');
        exit(0);
    }
    red('  ارسال ناموفق بود.');
    out('');
    out('  خطای دقیق:');
    out('    ' . (Mailer::$lastError !== '' ? Mailer::$lastError : '(بدون توضیح)'));
    out('');
    out('  رایج‌ترین علت‌ها:');
    out('    • Connection refused / timed out → هاست یا پورت اشتباه، یا فایروال');
    out('    • پاسخ 535 به AUTH             → نام کاربری یا رمز SMTP اشتباه');
    out('    • خطای TLS                      → SMTP_SECURE را عوض کنید (tls / ssl / none)');
    out('    • پاسخ 550 به MAIL FROM         → آدرس فرستنده باید متعلق به همان دامنه باشد');
    exit(1);
}

fail("دستور ناشناخته: $cmd  (--help را ببینید)");
