<?php
/**
 * ثبت‌نامِ خودسرویس.
 *
 * ⛔ فقط وقتی در دسترس است که مدیر روشنش کرده باشد. اگر خاموش باشد،
 *    این صفحه **وجود ندارد** (۴۰۴)، نه اینکه بگوید «غیرفعال است» —
 *    چون دومی به یک اسکنر می‌گوید اینجا اپی هست که ثبت‌نام دارد و فقط
 *    خاموش است.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/signup.php';

Auth::initSession();

// هنوز هیچ کاربری نیست → مسیرِ درست راه‌اندازیِ اولیه است، نه ثبت‌نام.
if (!Auth::hasAnyUser()) {
    header('Location: setup.php');
    exit;
}
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}
if (!signupEnabled()) {
    http_response_code(404);
    exit('Not found.');
}

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ⚠ مثل login.php، توکنِ کهنه بن‌بست نمی‌سازد: فرم دوباره با توکنِ
    //   تازه نشان داده می‌شود. صفحه‌ی ثبت‌نام هم مثل صفحه‌ی ورود ممکن
    //   است ساعت‌ها باز بماند.
    if (!Csrf::validate(postParam('csrf_token'))) {
        $error = 'نشست شما منقضی شده بود. دوباره تلاش کنید.';
    } else {
        $wait = signupThrottleMinutes($ip);
        if ($wait > 0) {
            $error = 'تعداد ثبت‌نام از این شبکه زیاد بوده. حدود '
                   . toPersianDigits($wait) . ' دقیقه دیگر دوباره تلاش کنید.';
        } else {
            $fullName = postParam('full_name');
            $username = postParam('username');
            $email    = trim(postParam('email'));
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['password_confirm'] ?? '';

            $pdo = Database::getConnection();
            $error = validateNewUser($pdo, $fullName, $username, $email, $password, $confirm);

            if ($error === '') {
                $res = createUserAccount($pdo, $fullName, $username, $email, $password, 'user');
                if (!$res['ok']) {
                    $error = $res['error'] ?? 'خطایی رخ داد.';
                } else {
                    signupThrottleRecord($ip);
                    // ⚠ اگر ایمیل ننشسته باشد، حساب ساخته شده ولی کاربر
                    //   باید بداند — در سکوت نگذر.
                    $note = ($res['error'] ?? '') !== ''
                        ? 'حساب ساخته شد، ولی ایمیل ثبت نشد: ' . $res['error']
                        : 'حساب شما ساخته شد. حالا وارد شوید.';
                    redirectWithMessage('login.php',
                        ($res['error'] ?? '') !== '' ? 'error' : 'success', $note);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>ساخت حساب | <?= h(APP_NAME) ?></title>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
    <link rel="apple-touch-icon" href="<?= iconUrl('icon-180.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= iconUrl('icon-32.png') ?>">
    <link rel="manifest" href="<?= APP_BASE_PATH ?>/assets/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#2a3563">
</head>
<body class="auth-body">
    <div class="auth-box auth-box-wide">
        <div class="auth-logo">
            <img src="<?= iconUrl('icon-180.png') ?>" alt="" class="auth-avatar">
            <h1>ساخت حساب</h1>
            <p class="auth-subtitle">دفترِ مالیِ خودتان را بسازید</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="form-group">
                <label for="full_name">نام و نام خانوادگی</label>
                <input type="text" id="full_name" name="full_name" required autofocus
                       maxlength="100" placeholder="مثلاً: علی محمدی" value="<?= h(postParam('full_name')) ?>">
            </div>
            <div class="form-group">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       autocomplete="username" maxlength="50"
                       placeholder="فقط حروف انگلیسی و عدد" value="<?= h(postParam('username')) ?>">
            </div>
            <div class="form-group">
                <label for="email">ایمیل</label>
                <input type="email" id="email" name="email" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       autocomplete="email" maxlength="190"
                       placeholder="you@gmail.com" value="<?= h(postParam('email')) ?>">
                <p class="hint">با همین ایمیل هم می‌توانید وارد شوید، و بازیابیِ رمز از همین‌جا انجام می‌شود.</p>
            </div>
            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input type="password" id="password" name="password" required
                       autocomplete="new-password" placeholder="<?= h(passwordHint()) ?>">
            </div>
            <div class="form-group">
                <label for="password_confirm">تکرار رمز عبور</label>
                <input type="password" id="password_confirm" name="password_confirm" required
                       autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-large">ساخت حساب</button>
        </form>

        <a href="<?= APP_BASE_PATH ?>/login.php" class="link-back"
           style="display:block;text-align:center;margin-top:14px">حساب دارم — وارد می‌شوم</a>
    </div>
</body>
</html>
