<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();

if (!Auth::hasAnyUser()) {
    header('Location: setup.php');
    exit;
}

if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if (getParam('switch_user') === '1') {
    Auth::forgetUsername();
    header('Location: login.php');
    exit;
}

$requireFullLogin = getSetting('require_full_login', '0') === '1';
$rememberedUsername = $requireFullLogin ? null : Auth::getRememberedUsername();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    $username = postParam('username');
    $password = $_POST['password'] ?? '';

    $result = Auth::attemptLogin($username, $password);

    if ($result['success']) {
        if (!$requireFullLogin) {
            Auth::rememberUsername($username);
        }
        // «این دستگاه را به خاطر بسپار» — تا ۳۰ روز رمز پرسیده نمی‌شود
        if (postParam('trust_device') === '1') {
            Auth::trustThisDevice((int)Auth::userId());
        }
        header('Location: index.php');
        exit;
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no">
    <title>ورود | <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_BASE_PATH ?>/assets/serve.php?f=css/style.css&v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>">
    <link rel="apple-touch-icon" href="<?= APP_BASE_PATH ?>/assets/icons/icon-180.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= APP_BASE_PATH ?>/assets/icons/icon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= APP_BASE_PATH ?>/assets/icons/icon-16.png">
    <link rel="manifest" href="<?= APP_BASE_PATH ?>/assets/manifest.php">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= h(APP_NAME) ?>">
    <meta name="theme-color" content="#0b0b0b">
</head>
<body class="auth-body">
    <div class="auth-box">
        <div class="auth-logo">
            <span class="brand-icon">💰</span>
            <h1><?= h(APP_NAME) ?></h1>
            <p class="auth-subtitle">مدیریت ساده درآمد و هزینه</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($rememberedUsername !== null): ?>
            <!-- فقط رمز عبور — نام کاربری روی همین دستگاه ذخیره شده -->
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="username" value="<?= h($rememberedUsername) ?>">

                <a href="login.php?switch_user=1" class="link-back">← ورود با نام کاربری دیگر</a>

                <div class="form-group">
                    <label for="password">رمز عبور — <?= h($rememberedUsername) ?></label>
                    <input type="password" id="password" name="password" required autofocus placeholder="رمز عبور خود را وارد کنید">
                </div>

                <label class="inline-check" style="margin:4px 0 14px;">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    <span>این دستگاه را ۳۰ روز به خاطر بسپار</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block">ورود</button>
            </form>
        <?php else: ?>
            <!-- ورود کامل (نام کاربری + رمز عبور) -->
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="username">نام کاربری</label>
                    <input type="text" id="username" name="username" required autofocus placeholder="نام کاربری خود را وارد کنید" value="<?= h(postParam('username')) ?>">
                </div>
                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <input type="password" id="password" name="password" required placeholder="رمز عبور خود را وارد کنید">
                </div>
                <label class="inline-check" style="margin:4px 0 14px;">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    <span>این دستگاه را ۳۰ روز به خاطر بسپار</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block">ورود</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
