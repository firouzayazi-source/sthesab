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
    <!-- viewport-fit=cover لازم است وگرنه iOS مقدار env(safe-area-inset-*)
         را صفر می‌دهد و همه‌ی محاسبه‌های حاشیه‌ی امن بی‌اثر می‌مانند. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>بازیابی رمز | <?= h(APP_NAME) ?></title>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
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
            <!-- کاربر باید در یک نگاه بفهمد کارِ بعدی‌اش چیست: برود ایمیلش
                 را ببیند. جمله‌ی محتاطانه («اگر حسابی وجود داشته باشد…»)
                 برای این است که این صفحه نگوید چه حسابی هست و چه نیست، ولی
                 نباید تیتر باشد — وگرنه کاربر نمی‌داند بالاخره فرستاده شد
                 یا نه و دوباره دکمه را می‌زند. -->
            <div class="check-mail">
                <div class="check-mail-icon" aria-hidden="true">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/>
                        <path d="M3 7l8.2 5.6a1.5 1.5 0 001.6 0L21 7"/>
                    </svg>
                </div>
                <h2 class="check-mail-title">صندوق ایمیل خود را ببینید</h2>
                <p class="check-mail-text">
                    اگر حسابی با
                    <b><?= h(postParam('identifier')) ?></b>
                    ثبت شده باشد، لینک تغییر رمز به ایمیلش فرستاده شد.
                </p>
            </div>

            <ul class="check-mail-notes">
                <li>پوشه‌ی <b>هرزنامه (Spam)</b> را هم نگاه کنید.</li>
                <li>لینک تا <?= toPersianDigits((string)PasswordReset::TTL_MINUTES) ?> دقیقه معتبر است و فقط یک بار کار می‌کند.</li>
                <li>رسیدن ایمیل ممکن است تا چند دقیقه طول بکشد.</li>
            </ul>

            <p id="resendNote" class="hint" style="text-align:center"></p>
            <a href="forgot-password.php" id="resendLink" class="link-back"
               data-wait="60" style="text-align:center;padding-bottom:6px">دوباره درخواست می‌دهم</a>

            <a href="login.php" class="btn btn-secondary btn-block" style="margin-top:6px">بازگشت به ورود</a>

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
                <button type="submit" class="btn btn-primary btn-block"
                        data-busy="در حال فرستادن…">فرستادن لینک بازیابی</button>
                <p class="hint" style="text-align:center;margin-top:10px">
                    فرستادن ایمیل چند ثانیه طول می‌کشد. یک بار بزنید و صبر کنید.
                </p>
            </form>
            <a href="login.php" class="link-back" style="display:block;margin-top:14px">← بازگشت به ورود</a>
        <?php endif; ?>
    </div>
<?php include __DIR__ . '/includes/auth_form_js.php'; ?>
</body>
</html>
