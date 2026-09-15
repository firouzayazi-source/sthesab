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
require_once __DIR__ . '/../includes/functions.php';

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
    out("  $me --unlock ali                       باز کردن قفلِ تلاش ناموفق ورود");
    out("  $me --unlock --all                     باز کردن قفل همه");
    out("  $me --test-mail you@gmail.com          آزمایش تنظیمات ایمیل");
    out("  $me --set-phone ali 09123456789        ثبت شماره برای ورود با پیامک");
    out("  $me --sms-check 09123456789            چرا کدِ ورود برای این شماره نمی‌رود؟");
    out("  $me --stats-check ali                  عددِ «آمار استفاده» این کاربر از کجا می‌آید؟");
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

$hasEmailColumn = usersHaveEmailColumn($pdo);

$cmd = $argvIn[0];

/** کاربر را با نام کاربری پیدا کن، وگرنه با پیام روشن بمیر */
$findUser = function (string $username) use ($pdo): array {
    $st = $pdo->prepare('SELECT * FROM users WHERE username = :u');
    $st->execute(['u' => $username]);
    $row = $st->fetch();
    if (!$row) {
        fail("کاربری با نام «{$username}» پیدا نشد. با --list فهرست را ببینید.");
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

        // توکن‌های اپ هم باطل شوند. `revokeAllAccessFor()` دستگاه‌ها را
        // دوباره پاک می‌کند (بی‌ضرر) ولی توکن‌های api/v1 را هم می‌گیرد —
        // و آن‌ها به رمز وابسته نیستند، پس رمزِ تازه بیرونشان نمی‌کرد.
        $apiRevoked = 0;
        try {
            $c = $pdo->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = :id AND revoked_at IS NULL');
            $c->execute(['id' => $user['id']]);
            $apiRevoked = (int)$c->fetchColumn();
            revokeAllAccessFor((int)$user['id']);
        } catch (Throwable $e) {
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
    if ($apiRevoked > 0) {
        out("    $apiRevoked توکن اپ موبایل باطل شد.");
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
    if ($user['is_active']) { out("کاربر «{$username}» از قبل فعال است."); exit(0); }
    $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $user['id']]);
    ok("کاربر «{$username}» فعال شد.");
    exit(0);
}

// ---------------------------------------------------------------
if ($cmd === '--set-email') {
    if (!$hasEmailColumn) {
        fail("ستون ایمیل هنوز ساخته نشده. اول:  bash deploy/migrate.sh --apply");
    }
    $username = $argvIn[1] ?? fail('استفاده — نمونه:  --set-email ali ali@gmail.com');
    $email    = $argvIn[2] ?? fail('استفاده — نمونه:  --set-email ali ali@gmail.com');
    $user = $findUser($username);

    // همان تابعی که پنل مدیر و صفحه‌ی پروفایل استفاده می‌کنند — قاعده یکی است
    if ($err = saveUserEmail($pdo, (int)$user['id'], $email)) { fail($err); }
    ok("ایمیل «{$email}» برای کاربر «{$username}» ثبت شد.");
    out('حالا می‌تواند از صفحه‌ی ورود، «رمز را فراموش کرده‌ام» را بزند.');
    exit(0);
}

// ---------------------------------------------------------------
// در پشتیِ سدِ حدس رمز.
//
// سد عمداً بین کاربر واقعی و مهاجم فرق نمی‌گذارد — نمی‌تواند — پس اگر
// خودتان چند بار رمز را غلط بزنید، ۱۵ دقیقه بیرون می‌مانید. این فرمان
// همان‌جا به کار می‌آید. مثل بقیه‌ی این فایل فقط از خط فرمان اجرا می‌شود،
// یعنی کسی که به سرور دسترسی دارد — و او از قبل هر کاری می‌توانست بکند.
if ($cmd === '--unlock') {
    if (!tableExists('login_attempts')) {
        fail("جدول login_attempts نیست. اول:  bash deploy/migrate.sh --apply");
    }

    $target = $argvIn[1] ?? fail("نام کاربری را بدهید، یا --all برای همه. نمونه:  --unlock ali");

    if ($target === '--all') {
        $st = $pdo->query('DELETE FROM login_attempts');
        ok("قفل ورود برای همه باز شد ({$st->rowCount()} تلاش ناموفق پاک شد).");
        exit(0);
    }

    // به نامِ ناموجود هم اجازه می‌دهیم: تلاش‌های ناموفق روی نام‌های
    // ناموجود هم ثبت می‌شوند، پس باید بشود پاکشان کرد.
    $st = $pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :u');
    $st->execute(['u' => mb_strtolower(trim($target))]);
    $n = $st->rowCount();

    if ($n === 0) {
        info("«{$target}» قفل نبود — چیزی برای پاک کردن نبود.");
    } else {
        ok("قفل ورود «{$target}» باز شد ({$n} تلاش ناموفق پاک شد).");
    }
    out('توجه: شمارنده‌ی IP جدا شمرده می‌شود و با این فرمان پاک نمی‌شود؛');
    out('برای آن از  --unlock --all  استفاده کنید.');
    exit(0);
}

// ---------------------------------------------------------------
// ⛔ همان در پشتی که `--set-email` برای بازیابیِ رمز است، برای ورودِ
//    پیامکی.
//
//    دلیلش یک بن‌بستِ واقعی است: فیلدِ شماره در پروفایل **فقط** وقتی
//    دیده می‌شود که ورودِ پیامکی از قبل کار کند، و ورودِ پیامکی تا وقتی
//    کسی شماره نداشته باشد برای هیچ‌کس کار نمی‌کند. روزِ اولی که پنل
//    راه می‌افتد، مالکِ نصب باید بتواند بدونِ مرورگر از این حلقه بیرون
//    بیاید — و اگر خودش هم بیرون از حساب مانده باشد، این تنها راه است.
if ($cmd === '--set-phone') {
    if (!tableHasColumn('users', 'phone')) {
        fail("ستون شماره هنوز ساخته نشده. اول:  bash deploy/migrate.sh --apply");
    }
    $username = $argvIn[1] ?? fail('استفاده — نمونه:  --set-phone ali 09123456789');
    $phoneIn  = $argvIn[2] ?? fail('استفاده — نمونه:  --set-phone ali 09123456789');
    $user = $findUser($username);

    // ⛔ از همان `saveUserPhone` می‌رود که پروفایل از آن می‌رود — نه یک
    //    UPDATE دستی. نرمال‌سازی و بررسیِ تکراری بودن باید یکی بماند،
    //    وگرنه شماره‌ای اینجا ذخیره می‌شود که `requestCode()` پیدایش
    //    نمی‌کند و خرابی **بی‌صداست**: کد فرستاده «می‌شود» و نمی‌رسد.
    if ($err = saveUserPhone($pdo, (int)$user['id'], $phoneIn)) { fail($err); }

    require_once __DIR__ . '/../includes/sms_login.php';
    $norm = SmsLogin::normalizePhone($phoneIn);
    ok("شماره «{$norm}» برای کاربر «{$username}» ثبت شد.");
    out('حالا می‌تواند از صفحه‌ی ورود، «ورود با کد پیامکی» را بزند.');
    out("برای اطمینان:  php deploy/user-admin.php --sms-check {$norm}");
    exit(0);
}

// ---------------------------------------------------------------
// ⛔ «کدِ ورود نمی‌آید» پنج علتِ کاملاً جدا دارد و **سه‌تایشان عمداً
//    ساکت‌اند**: صفحه‌ی ورود برای شماره‌ی ثبت‌نشده، حسابِ غیرفعال و
//    شماره‌ی موجود، همگی یک پیام می‌دهند تا فهرستِ کاربران لو نرود.
//    آن قاعده درست است و عوض نمی‌شود — ولی یعنی از بیرون هیچ راهی برای
//    تشخیص نیست و آدم ساعت‌ها دنبالِ پنلِ پیامک می‌گردد در حالی که
//    مشکل یک شماره‌ی ثبت‌نشده است.
//
// ⚠ این فقط از خط فرمان اجرا می‌شود (نگهبانِ بالای فایل)، پس چیزی که
//   صفحه‌ی ورود عمداً نمی‌گوید اینجا گفتنی است.
if ($cmd === '--sms-check') {
    require_once __DIR__ . '/../includes/sms_login.php';

    $raw = $argvIn[1] ?? fail("شماره را بدهید. نمونه:  --sms-check 09123456789");
    out('');
    info('— گام ۱: نرمال‌سازی شماره —');
    $phone = SmsLogin::normalizePhone($raw);
    if ($phone === null) {
        red("شماره «{$raw}» معتبر نیست. همین‌جا رد می‌شود و هیچ کدی نمی‌رود.");
        exit(1);
    }
    ok("«{$raw}»  →  {$phone}");

    out('');
    info('— گام ۲: آیا قابلیت اصلاً در دسترس است؟ —');
    $sw = SmsLogin::switchedOn();
    $tb = SmsLogin::tableReady();
    $cf = Sms::isConfigured();
    out(($sw ? '  ✓' : '  ✗') . ' کلیدِ مدیر (ورود با پیامک)');
    out(($tb ? '  ✓' : '  ✗') . ' جدول sms_codes و ستون users.phone');
    out(($cf ? '  ✓' : '  ✗') . ' پنلِ پیامک تنظیم شده (' . (Sms::method() ?: 'خاموش') . ')');
    if (!$sw || !$tb || !$cf) {
        red('در دسترس نیست — صفحه‌ی ورود با پیامک ۴۰۴ می‌دهد و هیچ کدی نمی‌رود.');
        exit(1);
    }
    ok('در دسترس است.');

    out('');
    info('— گام ۳: آیا کاربری با این شماره هست؟ —');
    // ⛔ همان کوئریِ خودِ requestCode، نه یک نسخه‌ی تازه — وگرنه این ابزار
    //    چیزی را می‌سنجد که اپ نمی‌سنجد و جوابش بی‌ارزش است.
    $st = $pdo->prepare('SELECT id, username, full_name, is_active FROM users WHERE phone = :p LIMIT 1');
    $st->execute(['p' => $phone]);
    $u = $st->fetch();

    if (!$u) {
        // ⛔ از وقتی «ثبت‌نام با شماره» آمد، نبودنِ کاربر دیگر به‌تنهایی
        //    علتِ «کد نمی‌آید» نیست: اگر هر دو کلیدِ مدیر روشن باشند،
        //    همان شماره کدِ **ثبت‌نام** می‌گیرد. بدونِ این شاخه، این ابزار
        //    روی سیستمِ سالم یک تشخیصِ غلط می‌داد و مالکِ نصب دنبالِ
        //    مشکلی می‌گشت که وجود ندارد.
        require_once __DIR__ . '/../includes/signup.php';
        if (phoneSignupEnabled()) {
            ok("هیچ کاربری با این شماره نیست، ولی **ثبت‌نام با شماره باز است**.");
            out('پس کد می‌رود و با تأییدش، کاربر یک رمز می‌گذارد و حسابش ساخته می‌شود.');
            out('⚠ اگر تا آن مرحله رفته و رمز نگذاشته، هیچ حسابی ساخته نشده است.');
            out('اگر نمی‌خواهید چنین شود، در «ورود و پیامک» کلیدِ');
            out('«ثبت‌نام آزاد برای همه» را خاموش کنید.');
            out('');
            exit(0);
        }

        red("هیچ کاربری با شماره‌ی {$phone} ثبت نشده است.");
        out('');
        out('علتِ «کد نمی‌آید» همین است. صفحه‌ی ورود عمداً می‌گوید «کد فرستاده شد»');
        out('تا فهرستِ کاربران لو نرود، ولی هیچ پیامکی نمی‌فرستد.');
        out('(ثبت‌نام با شماره خاموش است، وگرنه همین شماره حساب می‌ساخت.)');
        out('');
        out('⚠ شماره‌ای که در فرمِ ورود می‌زنید باید **دقیقاً** همان باشد که');
        out('  کاربر در «حساب کاربری من» ذخیره کرده. شماره‌های ثبت‌شده:');
        $all = $pdo->query("SELECT username, phone FROM users WHERE phone IS NOT NULL AND phone <> '' ORDER BY username");
        $any = false;
        foreach ($all as $row) { $any = true; out("    {$row['username']}: {$row['phone']}"); }
        if (!$any) { out('    (هیچ‌کس — هر کاربر باید یک بار در پروفایل شماره‌اش را ذخیره کند)'); }
        exit(1);
    }
    ok("کاربر «{$u['username']}» ({$u['full_name']})");
    if ((int)$u['is_active'] !== 1) {
        red('ولی حسابش **غیرفعال** است، پس هیچ کدی برایش نمی‌رود.');
        out("راه حل:  php deploy/user-admin.php --activate {$u['username']}");
        exit(1);
    }
    ok('و فعال است.');

    out('');
    info('— گام ۴: سقفِ درخواست —');
    // ⚠ این دو خصوصی‌اند و عمداً با Reflection خوانده می‌شوند: نوشتنِ
    //   دوباره‌ی همان کوئری یعنی نسخه‌ی دوم که از اصل عقب می‌افتد.
    $rw = new ReflectionMethod('SmsLogin', 'resendWait');
    $rw->setAccessible(true);
    $wait = (int)$rw->invoke(null, $phone);

    $cr = new ReflectionMethod('SmsLogin', 'countRecent');
    $cr->setAccessible(true);
    $perPhone = (int)$cr->invoke(null, 'phone', $phone);

    out("  فاصله تا درخواستِ بعدی: {$wait} ثانیه (سقف " . SmsLogin::RESEND_WAIT_SEC . ")");
    out("  درخواست در یک ساعتِ گذشته: {$perPhone} از " . SmsLogin::MAX_PER_PHONE);

    if ($wait > 0) {
        red("الان زود است — {$wait} ثانیه دیگر.");
        exit(1);
    }
    if ($perPhone >= SmsLogin::MAX_PER_PHONE) {
        red('به سقفِ ساعتی خورده — تا یک ساعت هیچ کدی نمی‌رود.');
        out('برای آزمودنِ فوری، ردیف‌های اخیرِ همین شماره را پاک کنید:');
        out("  mysql -e \"DELETE FROM sms_codes WHERE phone='{$phone}'\" hesab_db");
        exit(1);
    }
    ok('سقفی رد نشده.');

    // ⛔ دروازه‌ی ششم، و تازه‌ترینشان: کد **می‌رود** ولی ورود انجام
    //    نمی‌شود. این حالت از بقیه گیج‌کننده‌تر است چون کاربر پیامک را
    //    می‌گیرد و مطمئن است همه چیز درست کار می‌کند.
    out('');
    info('— گام ۵: آیا این کاربر حق دارد با پیامک وارد شود؟ —');
    $allow = SmsLogin::loginAllowedFor((int)$u['id']);
    if (!$allow['ok']) {
        red('نه. دلیل: ' . $allow['reason']);
        out('کد فرستاده می‌شود ولی پس از تأیید، ورود انجام نمی‌شود.');
        if ($allow['reason'] === 'need_pro') {
            out('ورودِ همیشگی با پیامک جزو نسخه‌ی کامل است و این کاربر رمز دارد،');
            out('پس می‌تواند با رمز وارد شود. برای باز کردنِ ورودِ پیامکی:');
            out('  در پنل مدیر به این کاربر دسترسی کامل بدهید، یا');
            out('  «اجرای محدودیت طرح» را خاموش کنید.');
        }
        exit(1);
    }
    ok('بله' . ($allow['reason'] === 'no_other_door'
        ? ' (این حساب رمز ندارد، پس پیامک تنها درِ اوست و همیشه باز است).'
        : '.'));

    out('');
    ok('هیچ دروازه‌ای بسته نیست — کدِ ورود برای این شماره باید برود.');
    out('اگر باز هم نرسید، مشکل از خودِ پنل است: در صفحه‌ی مدیر');
    out('«ارسال آزمایشی» بزنید و خطای خامِ پنل را ببینید.');
    exit(0);
}

// ---------------------------------------------------------------
// ⛔ «عددِ آمار غلط است» از بیرون یک شکل دارد و سه علتِ کاملاً جدا:
//    ۱. کد هنوز روی سرور نرفته (فقط در گیت است)،
//    ۲. رفته ولی جدولی که عدد را باد می‌کند در فهرستِ درست نیست،
//    ۳. خودِ `transactions` واقعاً همان‌قدر ردیف دارد (مثلاً ردیفِ سودِ
//       معامله یا تراکنشِ دوره‌ایِ خودکار).
//
//    هر سه یک نشانه می‌دهند: «۷ نوشته در حالی که ۱ تا بوده». حدس زدن
//    بینشان یعنی سه دورِ کامل deploy و آزمایش — همان دلیلی که
//    `--sms-check` ساخته شد.
//
// ⚠ این ابزار **فقط می‌خواند** و خودِ `userActivity()` را صدا می‌زند،
//   نه یک کوئریِ بازنویسی‌شده: وگرنه چیزی را می‌سنجید که صفحه
//   نمی‌سنجد و جوابش بی‌ارزش بود (همان درسِ Reflection در `--sms-check`).
if ($cmd === '--stats-check') {
    require_once __DIR__ . '/../includes/admin_insights.php';
    require_once __DIR__ . '/../includes/user_data.php';   // userDataTables()

    $username = $argvIn[1] ?? fail('نام کاربری را بدهید. نمونه:  --stats-check ali');
    $user     = $findUser($username);
    $uid      = (int)$user['id'];

    out('');
    info('— گام ۱: این نسخه‌ی کد چه چیزی را «رکورد» می‌شمارد؟ —');
    out('  ACTIVITY_TABLES (' . count(ACTIVITY_TABLES) . ' جدول):');
    out('    ' . implode('، ', ACTIVITY_TABLES));
    out('');
    out('  بیرونِ شمارش (' . count(NON_ACTIVITY_TABLES) . ' جدول):');
    foreach (NON_ACTIVITY_TABLES as $t => $why) {
        out(sprintf('    %-22s %s', $t, $why));
    }
    out('');
    // نشانه‌ی نسخه: اگر فهرست‌های بالا هنوز دسته‌بندی و اشخاص را جزوِ
    // «رکورد» می‌شمارند، یعنی کدِ روی این ماشین قدیمی است.
    $refs = ['categories', 'people', 'wallet_kinds', 'banks', 'asset_types'];
    $leak = array_values(array_intersect($refs, ACTIVITY_TABLES));
    if ($leak) {
        red('⛔ این نسخه هنوز فهرست‌های کمکی را «رکورد» می‌شمارد: ' . implode('، ', $leak));
        out('   یعنی کدِ روی این ماشین به‌روز نیست. اول:');
        out('     cd /opt/hesab/app && sudo ./deploy.sh');
        out('');
    } else {
        ok('فهرست‌های کمکی («فهرست‌های من») بیرونِ شمارش‌اند — این نسخه به‌روز است.');
        out('');
    }

    info('— گام ۲: این کاربر در هر جدول چند ردیف دارد؟ —');
    // ⚠ هر جدولِ `user_id`دار، نه فقط آن‌هایی که می‌شماریم — وگرنه
    //   جدولی که در هیچ‌کدام از دو فهرست نیست نامرئی می‌ماند.
    $all   = userDataTables();
    $total = 0;
    $counted = 0;
    foreach ($all as $t) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE user_id = :u");
        $st->execute(['u' => $uid]);
        $n = (int)$st->fetchColumn();
        if ($n === 0) { continue; }
        $total += $n;

        if (in_array($t, ACTIVITY_TABLES, true)) {
            $tag = 'شمرده می‌شود';
            $counted += $n;
        } elseif (isset(NON_ACTIVITY_TABLES[$t])) {
            $tag = 'شمرده نمی‌شود';
        } else {
            $tag = '⛔ در هیچ فهرستی نیست';
        }
        out(sprintf('    %-22s %5d   %s', $t, $n, $tag));
    }
    out('');
    out("  جمعِ همه‌ی ردیف‌ها: {$total}   ·   جمعِ شمرده‌شده: {$counted}");

    out('');
    info('— گام ۳: صفحه‌ی «آمار استفاده» چه می‌گوید؟ —');
    $row = null;
    foreach (userActivity() as $r) {
        if ((int)$r['id'] === $uid) { $row = $r; break; }
    }
    if ($row === null) {
        red('این کاربر در خروجیِ userActivity() نیست — یعنی ردیفِ users پیدا نشد.');
        exit(1);
    }

    $other = (int)$row['records'] - (int)$row['tx'];
    out("    tx         = {$row['tx']}");
    out("    records    = {$row['records']}");
    out("    days_since = " . ($row['days_since'] === null ? '(هرگز)' : $row['days_since']));
    out("    state      = {$row['state']}");
    out('');
    out('  و روی صفحه دقیقاً این‌طور دیده می‌شود:');
    out("    «{$row['tx']} تراکنش" . ($other > 0 ? " · {$other} رکوردِ دیگر" : '') . '»');

    out('');
    if ((int)$row['records'] !== $counted) {
        red('⛔ ناهم‌خوانی: جمعِ شمرده‌شده‌ی گام ۲ با records گام ۳ یکی نیست.');
        out('   یعنی کوئریِ تجمیع جدولی را می‌بیند که اینجا نشمردیم (یا برعکس).');
        exit(1);
    }
    ok('گام ۲ و گام ۳ با هم می‌خوانند.');
    out('');
    out('اگر عددِ «تراکنش» هنوز از انتظارِ شما بیشتر است، ردیف‌های اضافه');
    out('واقعاً در جدولِ transactions هستند. رایج‌ترین علتش این دو است:');
    out('  • سود/زیانِ هر فروشِ معامله یک تراکنش می‌سازد (syncTradeProfitTransactions)');
    out('  • تراکنشِ دوره‌ای در اولین بازدیدِ هر روز خودش ثبت می‌شود');
    out('برای دیدنشان، در خودِ اپ صفحه‌ی «تراکنش‌ها» را باز کنید.');
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
        'آزمایش تنظیمات ایمیل — ' . (defined('APP_NAME') ? APP_NAME : 'حساب لند'),
        '<div style="font-family:Tahoma;direction:rtl;text-align:right">'
        . '<h3>ارسال ایمیل درست کار می‌کند ✅</h3>'
        . '<p>این پیام آزمایشی از سرور ' . (defined('APP_NAME') ? APP_NAME : 'حساب لند') . ' فرستاده شده است.</p>'
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
