<?php
/**
 * تستِ ورود با کد پیامکی.
 *
 * ⛔ این قابلیت یک **راهِ ورودِ دومِ کاملِ** به هر حسابی است، پس هر
 *    ضعفش دقیقاً به معنیِ باز شدنِ حسابِ کاربر است. سه چیز خطرناک‌تر از
 *    بقیه‌اند و این تست از آن‌ها شروع می‌کند:
 *    ۱. **پیش‌فرض** — روشن شدنش با `git pull` یعنی دری که مالکِ نصب
 *       نمی‌داند وجود دارد.
 *    ۲. **سقفِ تلاش روی کد** — بی‌آن، کدِ ۵ رقمی در چند دقیقه حدس زده
 *       می‌شود و ورودِ پیامکی از ورودِ با رمز ضعیف‌تر می‌شود.
 *    ۳. **یک‌بارمصرف بودن** — کدِ استفاده‌شده باید بسوزد.
 *
 * ⚠ `SMS_METHOD` روی این نصب معمولاً خالی است، پس تست حالتِ `log` را
 *   **در همین پروسه** تعریف می‌کند و کدِ فرستاده‌شده را از
 *   `Sms::$sent` می‌خواند — بدون فرستادنِ هیچ پیامکِ واقعی.
 */

// ---------- نگهبان: فقط خط فرمان ----------
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/lib/assert.php';

if (!file_exists(__DIR__ . '/../config/config.php')) {
    T::group('ورود با پیامک');
    T::skip('تست ورود با پیامک', 'config/config.php وجود ندارد');
    exit(T::report());
}

// ⛔ پیش از لود شدنِ config: اگر نصب `SMS_METHOD` نداشته باشد، حالتِ
//    `log` تعریف می‌شود. اگر داشته باشد (نصبِ واقعی)، دست نمی‌خورد و
//    تست خودش را رد می‌کند — وگرنه ممکن بود پیامکِ واقعی برود.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_login.php';

if (!defined('SMS_METHOD')) {
    define('SMS_METHOD', 'log');
} elseif (SMS_METHOD !== 'log' && SMS_METHOD !== '') {
    T::group('ورود با پیامک');
    T::skip('تست ورود با پیامک', 'SMS_METHOD روی این نصب واقعی است — پیامک واقعی فرستاده می‌شد');
    exit(T::report());
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    T::group('ورود با پیامک');
    T::skip('تست ورود با پیامک', 'اتصال به دیتابیس برقرار نشد');
    exit(T::report());
}

// ---------------------------------------------------------------
T::group('نرمال‌سازیِ شماره — تنها جایی که شماره معنا می‌گیرد');

// ⛔ اگر ثبتِ شماره و ورود با شماره از دو نرمال‌سازی رد شوند، تطبیق
//    بی‌صدا شکست می‌خورد: کد می‌رود، کاربر می‌زندش، «پیدا نشد».
$good = [
    '09123456789'      => '09123456789',
    '+989123456789'    => '09123456789',
    '00989123456789'   => '09123456789',
    '989123456789'     => '09123456789',
    '9123456789'       => '09123456789',
    '0912 345 6789'    => '09123456789',
    '0912-345-6789'    => '09123456789',
    '۰۹۱۲۳۴۵۶۷۸۹'      => '09123456789',
];
$bad = [];
foreach ($good as $in => $want) {
    $got = SmsLogin::normalizePhone((string)$in);
    if ($got !== $want) { $bad[] = "{$in} → " . var_export($got, true) . " (باید {$want})"; }
}
T::bulk(count($good), $bad, 'همه‌ی شکل‌های یک شماره به یک مقدار می‌رسند');

$rejects = ['', '0912345678', '091234567890', '08123456789', 'abc', '1234567890', '+19123456789'];
$bad = [];
foreach ($rejects as $r) {
    if (SmsLogin::normalizePhone($r) !== null) { $bad[] = $r . ' پذیرفته شد'; }
}
T::bulk(count($rejects), $bad, 'شماره‌ی نامعتبر رد می‌شود (فقط موبایلِ ایران)');

// ---------------------------------------------------------------
T::group('پیش‌فرض خاموش است');

if (!SmsLogin::tableReady()) {
    T::skip('بقیه‌ی تست', 'migration_sms_login اجرا نشده است');
    exit(T::report());
}

$originalSetting = getSetting(SMS_LOGIN_SETTING, '0');

// ⚠ پنل حالا می‌تواند از پنلِ مدیر هم بیاید، نه فقط از `config.php`.
//   سرورِ توسعه‌ی پایین‌ترِ همین فایل یک **پروسه‌ی جدا**ست و `define`
//   این تست را نمی‌بیند؛ پس تنظیمِ دیتابیس را خودمان می‌گذاریم تا آن
//   پروسه هم پنلی داشته باشد، و آخرِ کار برش می‌گردانیم.
$originalSmsMethod = getSetting('sms_method', '');
setSetting('sms_method', 'log');

// ⛔ مهم‌ترین بررسیِ این فایل.
$pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k')
    ->execute(['k' => SMS_LOGIN_SETTING]);
T::same(false, SmsLogin::switchedOn(), '⛔ بدونِ تنظیم، ورود با پیامک خاموش است');
T::same(false, SmsLogin::available(),  '⛔ و در دسترس هم نیست');

setSetting(SMS_LOGIN_SETTING, '0');
T::same(false, SmsLogin::switchedOn(), 'با مقدار «۰» خاموش است');
setSetting(SMS_LOGIN_SETTING, '1');
T::same(true,  SmsLogin::switchedOn(), 'با مقدار «۱» روشن است');

// ---------- قربانی را خودمان می‌سازیم ----------
// تکیه بر داده‌ی موجود یعنی روی دیتابیسِ خالی بی‌صدا رد می‌شود.
$phone  = '0912' . random_int(1000000, 9999999);
$uname  = 'smstest_' . bin2hex(random_bytes(3));
$pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role, is_active, phone)
     VALUES (:f, :u, :h, :r, 1, :p)'
)->execute([
    'f' => 'کاربر آزمونِ پیامک', 'u' => $uname,
    'h' => password_hash('Test1234!', PASSWORD_DEFAULT), 'r' => 'user', 'p' => $phone,
]);
$victimId = (int)$pdo->lastInsertId();

$otherPhone = '0913' . random_int(1000000, 9999999);

register_shutdown_function(function () use ($pdo, $victimId, $originalSetting, $originalSmsMethod, $phone, $otherPhone) {
    setSetting('sms_method', $originalSmsMethod);
    $pdo->prepare('DELETE FROM sms_codes WHERE phone IN (:p, :o)')
        ->execute(['p' => $phone, 'o' => $otherPhone]);
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried IN (:p, :o)')
        ->execute(['p' => $phone, 'o' => $otherPhone]);
    $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $victimId]);
    setSetting(SMS_LOGIN_SETTING, $originalSetting);
});

/** آخرین کدِ خامی که «فرستاده» شد. */
$lastCode = function (): ?string {
    $n = count(Sms::$sent);
    if ($n === 0) { return null; }
    return preg_match('/(\d{' . SmsLogin::CODE_LENGTH . '})/', Sms::$sent[$n - 1]['text'], $m)
        ? $m[1] : null;
};
/** پنجره‌ی «۶۰ ثانیه صبر کن» را باز می‌کند تا تست چند بار کد بگیرد. */
$clearWait = function () use ($pdo, $phone) {
    $pdo->prepare('UPDATE sms_codes SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR)
                   WHERE phone = :p')->execute(['p' => $phone]);
};

// ---------------------------------------------------------------
T::group('کد فرستاده می‌شود و کار می‌کند');

Sms::$sent = [];
$r = SmsLogin::requestCode($phone, '10.0.0.1');
T::ok($r['success'], 'درخواستِ کد پذیرفته شد', $r['message'] ?? '');
T::same(true, $r['sent'] ?? false, 'پیامک به پنل تحویل داده شد');

$code = $lastCode();
T::ok($code !== null && strlen($code) === SmsLogin::CODE_LENGTH,
    'کدِ ' . SmsLogin::CODE_LENGTH . ' رقمی ساخته شد', (string)$code);

// ⛔ کدِ خام نباید در دیتابیس باشد.
$raw = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p AND code_hash = :c');
$raw->execute(['p' => $phone, 'c' => $code]);
T::same(0, (int)$raw->fetchColumn(), '⛔ کدِ خام در دیتابیس ذخیره نشده');

$hashed = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p AND code_hash = :c');
$hashed->execute(['p' => $phone, 'c' => hash('sha256', (string)$code)]);
T::same(1, (int)$hashed->fetchColumn(), 'فقط sha256 ذخیره شده');

$v = SmsLogin::verifyCode($phone, (string)$code, '10.0.0.1');
T::ok($v['success'], 'کدِ درست پذیرفته شد', $v['message'] ?? '');
T::same($victimId, (int)($v['user']['id'] ?? 0), 'همان کاربرِ صاحبِ شماره برگشت');

// ---------------------------------------------------------------
T::group('⛔ کد یک‌بارمصرف است');

// بدون این، هر کسی که متنِ پیامک را ببیند تا آخرِ پنجره وارد می‌شود.
$again = SmsLogin::verifyCode($phone, (string)$code, '10.0.0.1');
T::same(false, $again['success'], '⛔ همان کد بارِ دوم رد می‌شود');

// ---------------------------------------------------------------
T::group('⛔ سقفِ تلاش روی خودِ کد');

$clearWait();
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.2');
$real = $lastCode();
T::ok($real !== null, 'کدِ تازه گرفته شد');

// کدِ غلطی که قطعاً با کدِ درست فرق دارد
$wrongCode = str_pad((string)((((int)$real) + 1) % (10 ** SmsLogin::CODE_LENGTH)),
    SmsLogin::CODE_LENGTH, '0', STR_PAD_LEFT);

for ($i = 0; $i < SmsLogin::MAX_CODE_ATTEMPTS; $i++) {
    // ⚠ سدِ `LoginThrottle` هم روی همین شماره می‌شمارد و سقفش کمتر است،
    //   پس بین تلاش‌ها پاکش می‌کنیم تا واقعاً **سقفِ کد** سنجیده شود، نه
    //   سدِ عمومی. اگر این کار نمی‌شد، تست سبز می‌ماند حتی با برداشتنِ
    //   سقفِ کد — یعنی چیزی را می‌سنجید که فکر می‌کردیم.
    $pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);
    SmsLogin::verifyCode($phone, $wrongCode, '10.0.0.2');
}
$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);

$burned = SmsLogin::verifyCode($phone, (string)$real, '10.0.0.2');
T::same(false, $burned['success'],
    '⛔ بعد از ' . SmsLogin::MAX_CODE_ATTEMPTS . ' تلاشِ ناموفق، حتی کدِ **درست** هم رد می‌شود');

$st = $pdo->prepare('SELECT used_at FROM sms_codes WHERE phone = :p ORDER BY id DESC LIMIT 1');
$st->execute(['p' => $phone]);
T::ok($st->fetchColumn() !== null, 'کدِ سوخته در دیتابیس هم بسته شده');

// ---------------------------------------------------------------
T::group('⛔ کدِ منقضی کار نمی‌کند');

$clearWait();
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.3');
$expiring = $lastCode();
$pdo->prepare('UPDATE sms_codes SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
               WHERE phone = :p AND used_at IS NULL')->execute(['p' => $phone]);
$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);

$exp = SmsLogin::verifyCode($phone, (string)$expiring, '10.0.0.3');
T::same(false, $exp['success'], '⛔ کدِ منقضی رد می‌شود');

// ---------------------------------------------------------------
T::group('⛔ فقط شماره‌ی خودش، و بی‌آنکه وجودِ حساب لو برود');

Sms::$sent = [];
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :o')->execute(['o' => $otherPhone]);
$unknown = SmsLogin::requestCode($otherPhone, '10.0.0.4');

T::ok($unknown['success'], 'درخواست برای شماره‌ی ناموجود هم «موفق» است');
T::same(false, $unknown['sent'] ?? true, '⛔ ولی هیچ پیامکی فرستاده نشد');
T::same(0, count(Sms::$sent), 'و هیچ کدی ساخته نشد');

// ⛔ پیامِ یکسان: وگرنه این صفحه ابزارِ آماده‌ای برای فهمیدنِ «چه کسی
//    کاربرِ این سایت است» می‌شود.
$clearWait();
$known = SmsLogin::requestCode($phone, '10.0.0.4');
T::same($known['message'], $unknown['message'],
    '⛔ پیامِ شماره‌ی موجود و ناموجود دقیقاً یکی است');

$noRow = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :o');
$noRow->execute(['o' => $otherPhone]);
T::same(0, (int)$noRow->fetchColumn(), 'برای شماره‌ی ناموجود ردیفی هم ساخته نشد');

// ---------------------------------------------------------------
T::group('حسابِ غیرفعال با پیامک هم وارد نمی‌شود');

$pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :u')->execute(['u' => $victimId]);
$clearWait();
Sms::$sent = [];
$off = SmsLogin::requestCode($phone, '10.0.0.5');
T::same(false, $off['sent'] ?? true, 'برای حسابِ غیرفعال کدی فرستاده نمی‌شود');
$pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :u')->execute(['u' => $victimId]);

// ---------------------------------------------------------------
T::group('سقفِ درخواست — اینجا مسئله پول است');

// هر پیامک هزینه دارد؛ اندپوینتِ بی‌سقف اعتبارِ پنل را خالی می‌کند.
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
$clearWait();
Sms::$sent = [];

$accepted = 0;
for ($i = 0; $i < SmsLogin::MAX_PER_PHONE + 2; $i++) {
    // فقط پنجره‌ی «۶۰ ثانیه» را باز می‌کنیم، نه شمارشِ ساعتی — وگرنه
    // چیزی که سنجیده می‌شود همان سقفِ ساعتی نیست.
    $pdo->prepare('UPDATE sms_codes SET created_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                   WHERE phone = :p')->execute(['p' => $phone]);
    $rr = SmsLogin::requestCode($phone, '10.0.0.6');
    if (($rr['sent'] ?? false) === true) { $accepted++; }
}
T::same(SmsLogin::MAX_PER_PHONE, $accepted,
    '⛔ بیشتر از ' . SmsLogin::MAX_PER_PHONE . ' پیامک در ساعت فرستاده نمی‌شود');

// ---------------------------------------------------------------
T::group('فاصله‌ی لازم بین دو درخواست');

$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.7');
$immediate = SmsLogin::requestCode($phone, '10.0.0.7');
T::same(false, $immediate['success'], 'درخواستِ بلافاصله‌ی دوم رد می‌شود');
T::ok(($immediate['wait'] ?? 0) > 0, 'و می‌گوید چند ثانیه صبر کند',
    (string)($immediate['wait'] ?? 0));

// ---------------------------------------------------------------
T::group('⛔ کدهای بازِ قبلی سوزانده می‌شوند');

// دو کدِ همزمان معتبر یعنی دو برابر شدنِ شانسِ حدس، بی‌هیچ سودی.
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
Sms::$sent = [];
SmsLogin::requestCode($phone, '10.0.0.8');
$first = $lastCode();
$clearWait();
SmsLogin::requestCode($phone, '10.0.0.8');
$second = $lastCode();

$open = $pdo->prepare('SELECT COUNT(*) FROM sms_codes WHERE phone = :p AND used_at IS NULL');
$open->execute(['p' => $phone]);
T::same(1, (int)$open->fetchColumn(), '⛔ فقط یک کدِ باز می‌ماند');

$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);
$oldTry = SmsLogin::verifyCode($phone, (string)$first, '10.0.0.8');
T::same(false, $oldTry['success'], 'کدِ قبلی دیگر کار نمی‌کند');

$pdo->prepare('DELETE FROM login_attempts WHERE username_tried = :p')->execute(['p' => $phone]);
$newTry = SmsLogin::verifyCode($phone, (string)$second, '10.0.0.8');
T::ok($newTry['success'], 'کدِ تازه کار می‌کند', $newTry['message'] ?? '');

// ---------------------------------------------------------------
T::group('کلیدِ خاموش یعنی هیچ کدی نمی‌رود');

setSetting(SMS_LOGIN_SETTING, '0');
$pdo->prepare('DELETE FROM sms_codes WHERE phone = :p')->execute(['p' => $phone]);
Sms::$sent = [];
$offReq = SmsLogin::requestCode($phone, '10.0.0.9');
T::same(false, $offReq['success'], '⛔ با کلیدِ خاموش درخواست رد می‌شود');
T::same(0, count(Sms::$sent), 'و هیچ پیامکی فرستاده نمی‌شود');

$offVer = SmsLogin::verifyCode($phone, '12345', '10.0.0.9');
T::same(false, $offVer['success'], '⛔ سنجشِ کد هم با کلیدِ خاموش رد می‌شود');
setSetting(SMS_LOGIN_SETTING, '1');

// ---------------------------------------------------------------
T::group('صفحه‌ی sms-login.php واقعاً بسته است');

// «تنظیم خاموش است» با «صفحه بسته است» یکی نیست — همان درسی که
// قاعده‌ی nginx در api/v1 داد.
$port = 8124;
$doc  = dirname(__DIR__);
$srv  = proc_open(
    sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($doc)),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes
);

if (!is_resource($srv)) {
    T::skip('سنجشِ HTTP', 'سرور توسعه بالا نیامد');
} else {
    usleep(700000);
    $get = function (string $path) use ($port) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; }
        }
        return [$code, (string)$body];
    };

    setSetting(SMS_LOGIN_SETTING, '0');
    [$c1, $b1] = $get('/sms-login.php');
    T::same(404, $c1, '⛔ با کلیدِ خاموش، صفحه ۴۰۴ می‌دهد');
    T::ok(!str_contains($b1, 'شماره موبایل'), 'فرمش رندر نمی‌شود');

    [, $login] = $get('/login.php');
    T::ok(!str_contains($login, 'sms-login.php'),
        'لینکش در صفحه‌ی ورود نیست');

    setSetting(SMS_LOGIN_SETTING, '1');
    [$c2, ] = $get('/sms-login.php');
    T::same(200, $c2, 'با کلیدِ روشن و پنلِ تنظیم‌شده، صفحه باز می‌شود');

    [, $login2] = $get('/login.php');
    T::ok(str_contains($login2, 'sms-login.php'),
        'و لینکش در صفحه‌ی ورود ظاهر می‌شود');

    // ⛔ کلیدِ روشن ولی **پنلِ تنظیم‌نشده** نباید صفحه‌ای باز کند که
    //    هیچ کدی نمی‌فرستد — دو شرطِ جدا، و هر دو لازم.
    setSetting('sms_method', '');
    [$c3, ] = $get('/sms-login.php');
    T::same(404, $c3, '⛔ کلیدِ روشن ولی پنلِ تنظیم‌نشده = صفحه بسته می‌ماند');
    setSetting('sms_method', 'log');

    proc_terminate($srv);
    proc_close($srv);
}

// ---------------------------------------------------------------
// ⛔ هر کلیدِ روشن/خاموش فقط **یک نویسنده** داشته باشد.
//
// خرابیِ واقعی: کارتِ «ثبت‌نام» و کارتِ «ورود با پیامک» هر دو روی
// `SMS_LOGIN_SETTING` می‌نوشتند، ولی فرمِ ثبت‌نام آن فیلد را نداشت. پس
// هر بار که مدیر کارتِ ثبت‌نام را ذخیره می‌کرد، مقدارِ نبوده صفر خوانده
// می‌شد و **ورود با پیامک بی‌صدا خاموش می‌شد** — بی‌هیچ پیامی، و بدونِ
// اینکه ربطِ این دو به ذهنِ کسی برسد. کاربر فقط می‌دید «کار نمی‌کند».
T::group('⛔ هر تنظیم فقط یک نویسنده دارد');

// ⚠ **تعدادِ خودِ فراخوانی‌ها** شمرده می‌شود، نه تعدادِ فایل‌ها. نسخه‌ی
//   اول فایل می‌شمرد و آن‌وقت دو نویسنده در **یک** فایل از زیرش رد
//   می‌شدند — یعنی دقیقاً همان شکلی که باگ در آن ظاهر شده بود. جهش
//   نشانش داد.
$writers = static function (string $const) {
    $hits = [];
    foreach (glob(__DIR__ . '/../admin/*.php') as $f) {
        $n = substr_count(file_get_contents($f), 'setSetting(' . $const);
        for ($i = 0; $i < $n; $i++) { $hits[] = basename($f); }
    }
    return $hits;
};
foreach ([
    'SMS_LOGIN_SETTING'    => 'ورود با پیامک',
    'SIGNUP_SETTING'       => 'ثبت‌نام خودسرویس',
    'PLAN_ENFORCE_SETTING' => 'اعمال محدودیت طرح',
] as $const => $label) {
    $w = $writers($const);
    T::same(1, count($w), "کلیدِ «{$label}» فقط یک بار نوشته می‌شود",
        'نویسنده‌ها: ' . ($w ? implode('، ', $w) : 'هیچ'));
}

// ---------------------------------------------------------------
// ⛔ نوعِ حسابِ ملی‌پیامک — تنها جایی که این تصمیم گرفته می‌شود.
//
// خرابیِ واقعی: مالکِ یک حسابِ **قدیمی** فقط فیلدِ کلید را پر کرد.
// کدِ قبلی نوعِ حساب را از «خالی بودنِ نام کاربری» حدس می‌زد، پس به
// کنسول فرستاد و پنل جواب داد «کلید کنسول معتبر نیست» — پیامی که درست
// است و هیچ نمی‌گوید مشکل می‌تواند اصلاً نوعِ حساب باشد.
T::group('⛔ نوعِ حسابِ ملی‌پیامک: تنظیمِ صریح، نه حدس');

$smsKeys = ['sms_meli_mode', 'sms_user', 'sms_pass', 'sms_api_key', 'sms_pattern'];
$smsBack = [];
foreach ($smsKeys as $k) { $smsBack[$k] = getSetting($k, ''); }
$smsRestore = function () use ($smsKeys, $smsBack) {
    foreach ($smsKeys as $k) { setSetting($k, $smsBack[$k]); }
};

// ⚠ اگر `config.php` این‌ها را تعریف کرده باشد، ثابت برنده است و این
//   بخش چیزی جز خودِ config را نمی‌سنجد — پس رد می‌شود، نه شکست.
$constOverrides = false;
foreach (['SMS_MELI_MODE', 'SMS_USER', 'SMS_PASS', 'SMS_API_KEY'] as $c) {
    if (defined($c) && (string)constant($c) !== '') { $constOverrides = true; }
}

if ($constOverrides) {
    T::skip('نوعِ حسابِ ملی‌پیامک', 'config.php این کلیدها را تعریف کرده و برنده است');
} else {
    setSetting('sms_pattern', '12345');

    // ⚠ سازگاری با نصب‌هایی که امروز کار می‌کنند: مقدارِ نداشته یعنی
    //   همان حدسِ قبلی. اگر این بشکند، نصبِ موجود بی‌صدا مسیرش عوض
    //   می‌شود.
    setSetting('sms_meli_mode', '');
    setSetting('sms_user', 'olduser');
    setSetting('sms_pass', 'oldpass');
    T::same('panel', Sms::meliMode(), 'بدونِ تنظیم و با نام کاربری → روشِ قدیمی (رفتارِ قبلی)');

    setSetting('sms_user', '');
    setSetting('sms_api_key', 'somekey');
    T::same('console', Sms::meliMode(), 'بدونِ تنظیم و بدونِ نام کاربری → کنسول (رفتارِ قبلی)');

    // ⛔ و حالا همان حالتی که کاربر در آن گیر کرد: حسابِ قدیمی، ولی
    //    نام کاربری هنوز پر نشده. با حدس، به کنسول می‌رفت.
    setSetting('sms_meli_mode', 'panel');
    T::same('panel', Sms::meliMode(),
        '⛔ تنظیمِ صریح بر حدس مقدم است — حتی وقتی نام کاربری خالی است');
    T::ok(str_contains(Sms::missingFor('melipayamak'), 'نام کاربری'),
        'و صریح می‌گوید نام کاربری کم است، نه اینکه به کنسول بفرستد');

    setSetting('sms_meli_mode', 'console');
    setSetting('sms_user', 'olduser');
    T::same('console', Sms::meliMode(),
        '⛔ و برعکس: نام کاربریِ پرشده کنسول را از کار نمی‌اندازد');

    // آماده بودن: کنسول فقط کلید و الگو می‌خواهد.
    setSetting('sms_api_key', 'somekey');
    T::same('', Sms::missingFor('melipayamak'), 'کنسول با کلید و الگو آماده است');
    setSetting('sms_api_key', '');
    T::ok(str_contains(Sms::missingFor('melipayamak'), 'کلید وب‌سرویس'),
        'و بی‌کلید صریح می‌گوید چه چیزی کم است');

    // روشِ قدیمی: رمزِ ذخیره‌شده در فیلدِ کلید هنوز باید بخواند
    // (نصب‌های قبل از این تغییر همان‌طور ذخیره شده‌اند).
    setSetting('sms_meli_mode', 'panel');
    setSetting('sms_pass', '');
    setSetting('sms_api_key', 'passInKeyField');
    T::same('', Sms::missingFor('melipayamak'),
        'روشِ قدیمی: رمزِ ذخیره‌شده در فیلدِ کلید هنوز پذیرفته می‌شود');

    $smsRestore();
}

exit(T::report());
