<?php
/**
 * تعیین رمز تازه با لینکی که به ایمیل رفته است.
 *
 * برخلاف صفحه‌ی درخواست، اینجا پیام‌ها صریح‌اند (لینک منقضی شده، قبلاً
 * استفاده شده، …). دلیلش این است که تا اینجا فقط کسی می‌رسد که خودِ
 * لینک را دارد، پس چیزی افشا نمی‌شود و پیام مبهم فقط کاربر را سردرگم
 * می‌کند.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/password_reset.php';

Auth::initSession();

$selector  = getParam('s');
$validator = getParam('t');

// در حالت POST، توکن از فرم می‌آید (تا در نوار آدرس نماند)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selector  = postParam('s');
    $validator = postParam('t');
}

$reasons = [
    'malformed' => 'لینک ناقص است. کل آدرس ایمیل را کپی کنید.',
    'not-found' => 'این لینک معتبر نیست.',
    'mismatch'  => 'این لینک معتبر نیست.',
    'used'      => 'این لینک قبلاً استفاده شده است. اگر باز هم لازم دارید، درخواست تازه بدهید.',
    'expired'   => 'مهلت این لینک تمام شده است. درخواست تازه بدهید.',
    'inactive'  => 'این حساب غیرفعال است. با مدیر تماس بگیرید.',
    'weak'      => 'رمز باید حداقل ۸ کاراکتر باشد.',
    'db-error'  => 'خطایی رخ داد. دوباره تلاش کنید.',
];

$check   = PasswordReset::verify((string)$selector, (string)$validator);
$error   = '';
$success = false;

if (!$check['ok']) {
    $error = $reasons[$check['reason']] ?? 'این لینک معتبر نیست.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    $p1 = $_POST['password'] ?? '';
    $p2 = $_POST['password_confirm'] ?? '';

    if ($p1 !== $p2) {
        $error = 'دو رمز یکسان نیستند.';
    } elseif (mb_strlen($p1) < 8) {
        $error = $reasons['weak'];
    } else {
        $done = PasswordReset::complete((string)$selector, (string)$validator, $p1);
        if ($done['ok']) {
            $success = true;
        } else {
            $error = $reasons[$done['reason']] ?? 'تغییر رمز انجام نشد.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no">
    <title>تعیین رمز تازه | <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/serve.php?f=css/style.css&v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>">
    <link rel="apple-touch-icon" href="<?= APP_BASE_PATH ?>/assets/icons/icon-180.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= APP_BASE_PATH ?>/assets/icons/icon-32.png">
    <meta name="theme-color" content="#0b0b0b">
    <meta name="referrer" content="no-referrer">
</head>
<body class="auth-body">
    <div class="auth-box">
        <div class="auth-logo">
            <span class="brand-icon">💰</span>
            <h1><?= h(APP_NAME) ?></h1>
            <p class="auth-subtitle">تعیین رمز تازه</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                رمز عوض شد. حالا می‌توانید با رمز تازه وارد شوید.
            </div>
            <p style="color:var(--muted);font-size:13.5px;line-height:2">
                برای امنیت، همه‌ی دستگاه‌های مورد اعتماد باطل شدند؛ روی هر دستگاه
                یک بار دیگر باید وارد شوید.
            </p>
            <a href="login.php" class="btn btn-primary btn-block" style="margin-top:10px">رفتن به صفحه‌ی ورود</a>

        <?php elseif (!$check['ok']): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
            <a href="forgot-password.php" class="btn btn-primary btn-block" style="margin-top:10px">درخواست لینک تازه</a>
            <a href="login.php" class="link-back" style="display:block;margin-top:14px">← بازگشت به ورود</a>

        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <p style="color:var(--muted);font-size:13.5px;margin-bottom:14px">
                حساب: <b><?= h($check['user']['username']) ?></b>
            </p>

            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="s" value="<?= h((string)$selector) ?>">
                <input type="hidden" name="t" value="<?= h((string)$validator) ?>">

                <div class="form-group">
                    <label for="password">رمز تازه</label>
                    <input type="password" autocomplete="new-password" id="password" name="password" required autofocus
                           minlength="8" placeholder="حداقل ۸ کاراکتر">
                </div>
                <div class="form-group">
                    <label for="password_confirm">تکرار رمز تازه</label>
                    <input type="password" autocomplete="new-password" id="password_confirm" name="password_confirm" required
                           minlength="8" placeholder="همان رمز را دوباره بنویسید">
                </div>
                <button type="submit" class="btn btn-primary btn-block">ثبت رمز تازه</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
