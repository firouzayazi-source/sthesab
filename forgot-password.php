<?php
/**
 * درخواست بازیابی رمز.
 *
 * نکته‌ی امنیتی اصلی: پاسخ صفحه در همه‌ی حالت‌ها یکی است — چه کاربر
 * وجود داشته باشد، چه نه، چه ایمیل ثبت کرده باشد، چه نه. وگرنه این
 * صفحه به ابزاری برای کشف نام‌های کاربری تبدیل می‌شود.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/password_reset.php';

Auth::initSession();

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$mailReady = Mailer::isConfigured();
$done      = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mailReady) {
    Csrf::verifyOrFail(postParam('csrf_token'));

    $identifier = postParam('identifier');
    if ($identifier === '') {
        $error = 'نام کاربری یا ایمیل خود را وارد کنید.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // پاک کردن توکن‌های خیلی قدیمی — ارزان و بی‌ضرر
        PasswordReset::purgeExpired();

        $result = PasswordReset::request($identifier, $ip, appBaseUrl());

        // نتیجه‌ی واقعی فقط در لاگ می‌رود؛ کاربر همیشه یک پیام می‌بیند
        if (!$result['sent'] && $result['error'] !== '' && $result['error'] !== 'no-target') {
            error_log('forgot-password: ' . $result['error'] . ' — ' . $identifier);
        }
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no">
    <title>بازیابی رمز | <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/serve.php?f=css/style.css&v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>">
    <link rel="apple-touch-icon" href="<?= APP_BASE_PATH ?>/assets/icons/icon-180.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= APP_BASE_PATH ?>/assets/icons/icon-32.png">
    <meta name="theme-color" content="#0b0b0b">
</head>
<body class="auth-body">
    <div class="auth-box">
        <div class="auth-logo">
            <img src="<?= APP_BASE_PATH ?>/assets/icons/icon-192.png" alt="" class="brand-icon brand-icon-img" width="64" height="64">
            <h1><?= h(APP_NAME) ?></h1>
            <p class="auth-subtitle">بازیابی رمز عبور</p>
        </div>

        <?php if (!$mailReady): ?>
            <div class="alert alert-error">
                بازیابی با ایمیل روی این نصب فعال نیست.
            </div>
            <p style="color:var(--muted);font-size:13.5px;line-height:2">
                برای فعال‌کردنش، مقادیر <code>MAIL_METHOD</code> و مربوط به آن را در
                <code>config/config.php</code> تنظیم کنید. تا آن وقت، مدیر می‌تواند
                رمز را از خط فرمان سرور عوض کند.
            </p>
            <a href="login.php" class="link-back">← بازگشت به ورود</a>

        <?php elseif ($done): ?>
            <div class="alert alert-success">
                اگر حسابی با این مشخصات وجود داشته باشد و ایمیلی برایش ثبت شده باشد،
                لینک بازیابی فرستاده شد.
            </div>
            <p style="color:var(--muted);font-size:13.5px;line-height:2">
                پوشه‌ی هرزنامه را هم نگاه کنید. لینک تا
                <?= toPersianDigits((string)PasswordReset::TTL_MINUTES) ?> دقیقه معتبر است
                و فقط یک بار کار می‌کند.
            </p>
            <a href="login.php" class="link-back">← بازگشت به ورود</a>

        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="identifier">نام کاربری یا ایمیل</label>
                    <input type="text" id="identifier" name="identifier" required autofocus
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           autocomplete="username"
                           placeholder="نام کاربری یا ایمیل خود را وارد کنید"
                           value="<?= h(postParam('identifier')) ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-block">فرستادن لینک بازیابی</button>
            </form>
            <a href="login.php" class="link-back" style="display:block;margin-top:14px">← بازگشت به ورود</a>
        <?php endif; ?>
    </div>
</body>
</html>
