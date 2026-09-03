<?php
/**
 * ورود با کد پیامکی.
 *
 * ⛔ مثل `register.php`: اگر در دسترس نباشد این صفحه **وجود ندارد**
 *    (۴۰۴)، نه اینکه بگوید «غیرفعال است». پیامِ «غیرفعال» به یک اسکنر
 *    می‌گوید اینجا راهِ ورودِ دومی هست که فقط خاموش است.
 *
 * ⛔ دو مرحله در یک صفحه، ولی مرحله‌ی دوم شماره را در یک فیلدِ پنهان
 *    حمل می‌کند نه در نشست: کاربر ممکن است پیامک را روی گوشیِ دیگری
 *    ببیند، صفحه را ببندد، یا اپ نشست را جمع کرده باشد. حمل در فرم
 *    هیچ چیزی را ناامن نمی‌کند — خودِ کد است که احراز می‌کند.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/sms_login.php';

Auth::initSession();

if (!Auth::hasAnyUser()) {
    header('Location: setup.php');
    exit;
}
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}
if (!SmsLogin::available()) {
    http_response_code(404);
    exit('Not found.');
}

$error   = '';
$notice  = '';
$phone   = trim(postParam('phone'));
$step    = 'phone';
$ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ⚠ مثل login.php و register.php: توکنِ کهنه بن‌بست نمی‌سازد.
    if (!Csrf::validate(postParam('csrf_token'))) {
        $error = 'نشست شما منقضی شده بود. دوباره تلاش کنید.';
        $step  = postParam('step') === 'code' ? 'code' : 'phone';
    } elseif (postParam('step') === 'code') {
        $res = SmsLogin::verifyCode($phone, postParam('code'), $ip);
        if ($res['success']) {
            Auth::establishSession($res['user']);

            // همان دو کارِ همیشگیِ ورودِ موفق. «به خاطر بسپار» اینجا هم
            // هست، وگرنه کاربری که با پیامک وارد شده دفعه‌ی بعد باز
            // همان راه را می‌رفت — یعنی یک پیامکِ دیگر، با هزینه‌اش.
            if (postParam('trust_device') === '1') {
                Auth::trustThisDevice((int)$res['user']['id']);
            }
            Auth::rememberUsername((string)$res['user']['username']);

            header('Location: index.php');
            exit;
        }
        $error = $res['message'];
        $step  = 'code';
    } else {
        $res = SmsLogin::requestCode($phone, $ip);
        if ($res['success']) {
            $notice = $res['message'];
            $step   = 'code';
        } else {
            $error = $res['message'];
            // وقتی فقط باید صبر کند، برگرداندنش به مرحله‌ی اول یعنی
            // شماره را دوباره بزند — و کدی که در دست دارد را هدر بدهد.
            $step  = isset($res['wait']) ? 'code' : 'phone';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>ورود با پیامک | <?= h(APP_NAME) ?></title>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <link rel="apple-touch-icon" href="<?= iconUrl('icon-180.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= iconUrl('icon-32.png') ?>">
    <link rel="manifest" href="<?= APP_BASE_PATH ?>/assets/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0b0b0b">
</head>
<body class="auth-body">
    <div class="auth-box">
        <div class="auth-logo">
            <img src="<?= iconUrl('icon-180.png') ?>" alt="" class="auth-avatar auth-avatar-app" width="76" height="76">
            <h1>ورود با پیامک</h1>
            <p class="auth-subtitle"><?= $step === 'code' ? 'کد پیامک‌شده را وارد کنید' : 'شماره موبایلِ ثبت‌شده در حساب' ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="alert alert-success"><?= h($notice) ?></div>
        <?php endif; ?>

        <?php if ($step === 'code'): ?>
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="step" value="code">
                <input type="hidden" name="phone" value="<?= h($phone) ?>">

                <div class="form-group">
                    <label for="code">کد <?= toPersianDigits(SmsLogin::CODE_LENGTH) ?> رقمی</label>
                    <?php /* inputmode=numeric تا روی گوشی صفحه‌کلیدِ عددی بیاید،
                             و autocomplete=one-time-code تا iOS خودش کد را از
                             پیامک پیشنهاد بدهد. */ ?>
                    <input type="text" id="code" name="code" required autofocus
                           inputmode="numeric" autocomplete="one-time-code"
                           maxlength="<?= (int)SmsLogin::CODE_LENGTH ?>" class="otp-input"
                           placeholder="<?= str_repeat('•', SmsLogin::CODE_LENGTH) ?>">
                    <p class="hint">
                        به <?= toPersianDigits(h($phone)) ?> فرستاده شد ·
                        اعتبار <?= toPersianDigits(SmsLogin::CODE_TTL_MIN) ?> دقیقه
                    </p>
                </div>

                <label class="switch" style="margin:4px 0 16px;">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    <span class="switch-track"><span class="switch-knob"></span></span>
                    <span class="switch-text">این دستگاه را به خاطر بسپار</span>
                </label>

                <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ورود…">ورود</button>
            </form>

            <form method="POST" style="margin-top:10px;">
                <?= Csrf::field() ?>
                <input type="hidden" name="phone" value="<?= h($phone) ?>">
                <button type="submit" class="btn btn-secondary btn-block">کد را دوباره بفرست</button>
            </form>
        <?php else: ?>
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="phone">شماره موبایل</label>
                    <input type="tel" id="phone" name="phone" required autofocus
                           inputmode="tel" autocomplete="tel" maxlength="20"
                           placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="<?= h($phone) ?>">
                    <p class="hint">همان شماره‌ای که در پروفایلِ حسابتان ثبت شده است.</p>
                </div>
                <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ارسال…">فرستادن کد</button>
            </form>
        <?php endif; ?>

        <a href="<?= APP_BASE_PATH ?>/login.php" class="link-back"
           style="display:block;text-align:center;margin-top:14px">ورود با رمز عبور</a>
    </div>
<?php include __DIR__ . '/includes/auth_form_js.php'; ?>
</body>
</html>
