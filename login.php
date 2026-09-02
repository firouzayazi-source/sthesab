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

// اگر این دستگاه کاربری را به خاطر دارد، عکس پروفایل خودش را نشان می‌دهیم
// نه آیکن برنامه را — صفحه‌ی ورود این‌طور «مال خودش» به نظر می‌رسد.
// اگر عکسی ثبت نکرده باشد، آیکن برنامه در همان قاب گرد می‌نشیند.
$loginAvatar = null;
if ($rememberedUsername !== null && tableHasColumn('users', 'avatar')) {
    try {
        $av = Database::getConnection()->prepare(
            'SELECT avatar FROM users WHERE username = :u AND is_active = 1 LIMIT 1'
        );
        $av->execute(['u' => $rememberedUsername]);
        $file = (string)$av->fetchColumn();
        if ($file !== '' && is_file(__DIR__ . '/uploads/avatars/' . basename($file))) {
            $loginAvatar = basename($file);
        }
    } catch (PDOException $e) {
        $loginAvatar = null;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // اینجا عمداً verifyOrFail نیست.
    //
    // صفحه‌ی ورود بیشتر از هر صفحه‌ی دیگری باز می‌ماند: کاربر بازش می‌کند،
    // حواسش پرت می‌شود، بعداً برمی‌گردد و رمز را می‌زند. اگر در این فاصله
    // نشست جمع شده باشد، توکن هم رفته و verifyOrFail یک صفحه‌ی سفید با
    // متن خام نشان می‌داد — بن‌بستِ کامل، درست وقتی کاربر می‌خواست وارد شود.
    //
    // حالا فرم دوباره با توکن تازه رندر می‌شود و کاربر فقط یک بار دیگر
    // می‌زند. امنیت کم نمی‌شود: توکنِ نامعتبر همچنان وارد نمی‌کند، فقط
    // به‌جای مردن، راه برگشت می‌دهد.
    if (!Csrf::validate(postParam('csrf_token'))) {
        $error = 'نشست شما منقضی شده بود. لطفاً دوباره تلاش کنید.';
    } else {
        $username = postParam('username');
        $password = $_POST['password'] ?? '';

        $result = Auth::attemptLogin($username, $password);

        if ($result['success']) {
            if (!$requireFullLogin) {
                Auth::rememberUsername($username);
            }
            // «این دستگاه را به خاطر بسپار». مدتش دیگر ثابت نیست: از تنظیم
            // «بعد از چقدر بی‌فعالیتی دوباره رمز بپرسد» در پروفایل می‌آید
            // و با هر استفاده از نو شروع می‌شود (کشویی).
            if (postParam('trust_device') === '1') {
                Auth::trustThisDevice((int)Auth::userId());
            }
            header('Location: index.php');
            exit;
        }

        $error = $result['message'];
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
    <title>ورود | <?= h(APP_NAME) ?></title>
    <?php foreach (assetUrls(['css/style.css']) as $__u): ?>
    <link rel="stylesheet" href="<?= h($__u) ?>">
    <?php endforeach; ?>
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
            <?php if ($loginAvatar !== null): ?>
                <img src="<?= APP_BASE_PATH ?>/uploads/avatars/<?= h($loginAvatar) ?>" alt=""
                     class="auth-avatar" width="76" height="76">
            <?php else: ?>
                <img src="<?= APP_BASE_PATH ?>/assets/icons/icon-192.png" alt=""
                     class="auth-avatar auth-avatar-app" width="76" height="76">
            <?php endif; ?>
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
                    <input type="password" autocomplete="current-password" id="password" name="password" required autofocus placeholder="رمز عبور خود را وارد کنید">
                </div>

                <label class="switch" style="margin:4px 0 16px;">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    <span class="switch-track"><span class="switch-knob"></span></span>
                    <span class="switch-text">این دستگاه را به خاطر بسپار</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ورود…">ورود</button>
                <a href="forgot-password.php" class="link-back" style="display:block;text-align:center;margin-top:14px">رمز عبور را فراموش کرده‌ام</a>
            </form>
        <?php else: ?>
            <!-- ورود کامل (نام کاربری + رمز عبور) -->
            <form method="POST" class="auth-form" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="form-group">
                    <label for="username">نام کاربری یا ایمیل</label>
                    <input type="text" id="username" name="username" required autofocus
                           autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="text"
                           autocomplete="username" placeholder="نام کاربری یا ایمیل" value="<?= h(postParam('username')) ?>">
                </div>
                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <input type="password" autocomplete="current-password" id="password" name="password" required placeholder="رمز عبور خود را وارد کنید">
                </div>
                <label class="switch" style="margin:4px 0 16px;">
                    <input type="checkbox" name="trust_device" value="1" checked>
                    <span class="switch-track"><span class="switch-knob"></span></span>
                    <span class="switch-text">این دستگاه را به خاطر بسپار</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block" data-busy="در حال ورود…">ورود</button>
                <a href="forgot-password.php" class="link-back" style="display:block;text-align:center;margin-top:14px">رمز عبور را فراموش کرده‌ام</a>
            </form>
        <?php endif; ?>
    </div>
<?php include __DIR__ . '/includes/auth_form_js.php'; ?>
</body>
</html>
