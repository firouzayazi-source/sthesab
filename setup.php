<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

Auth::initSession();

if (Auth::hasAnyUser()) {
    header('Location: login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail(postParam('csrf_token'));

    $fullName = postParam('full_name');
    $username = postParam('username');
    $email    = trim(postParam('email'));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($fullName === '' || $username === '' || $password === '') {
        $error = 'تمام فیلدها الزامی هستند.';
    } elseif ($email === '') {
        // ایمیل اجباری است: بدون آن، مدیرِ اول هیچ راهی برای بازیابی
        // رمز ندارد و اگر فراموشش کند کسی نمی‌تواند کمکش کند.
        $error = 'ایمیل الزامی است — بدون آن امکان بازیابی رمز وجود ندارد.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'ایمیل معتبر نیست.';
    } elseif (mb_strlen($password) < 6) {
        $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'رمز عبور و تکرار آن یکسان نیستند.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) {
        $error = 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه و آندرلاین باشد.';
    } else {
        $pdo = Database::getConnection();
        try {
            $pdo->beginTransaction();

            $check = $pdo->query('SELECT COUNT(*) AS cnt FROM users FOR UPDATE')->fetch();
            if ((int)$check['cnt'] > 0) {
                $pdo->rollBack();
                header('Location: login.php');
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            // ستون email با migration_password_reset می‌آید؛ روی نصبی که
            // هنوز اجرا نشده باشد، ساخت مدیر نباید شکست بخورد.
            $hasEmail = usersHaveEmailColumn($pdo);

            if ($hasEmail) {
                $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email, password_hash, role, is_active) VALUES (:full_name, :username, :email, :password_hash, "admin", 1)');
                $stmt->execute([
                    'full_name'     => $fullName,
                    'username'      => $username,
                    'email'         => $email,
                    'password_hash' => $hash,
                ]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (full_name, username, password_hash, role, is_active) VALUES (:full_name, :username, :password_hash, "admin", 1)');
                $stmt->execute([
                    'full_name'     => $fullName,
                    'username'      => $username,
                    'password_hash' => $hash,
                ]);
            }

            $pdo->commit();

            redirectWithMessage('login.php', 'success', 'حساب مدیر با موفقیت ساخته شد. اکنون وارد شوید.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Setup Error: ' . $e->getMessage());
            $error = 'خطایی در ساخت حساب رخ داد. لطفاً دوباره تلاش کنید.';
        }
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
    <title>راه‌اندازی اولیه | <?= h(APP_NAME) ?></title>
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
    <div class="auth-box auth-box-wide">
        <div class="auth-logo">
            <span class="brand-icon">⚙️</span>
            <h1>راه‌اندازی اولیه</h1>
            <p class="auth-subtitle">برای شروع، حساب مدیر اصلی را بسازید</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="form-group">
                <label for="full_name">نام و نام خانوادگی</label>
                <input type="text" id="full_name" name="full_name" required autofocus placeholder="مثلاً: علی محمدی" value="<?= h(postParam('full_name')) ?>">
            </div>
            <div class="form-group">
                <label for="username">نام کاربری</label>
                <input type="text" id="username" name="username" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       autocomplete="username" placeholder="فقط حروف انگلیسی و عدد" value="<?= h(postParam('username')) ?>">
            </div>
            <div class="form-group">
                <label for="email">ایمیل</label>
                <input type="email" id="email" name="email" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       autocomplete="email" maxlength="190"
                       placeholder="مثلاً: you@gmail.com" value="<?= h(postParam('email')) ?>">
                <p class="hint">با همین ایمیل هم می‌توانید وارد شوید، و اگر رمز را فراموش کردید بازیابی از همین‌جا انجام می‌شود.</p>
            </div>
            <div class="form-group">
                <label for="password">رمز عبور</label>
                <input type="password" autocomplete="new-password" id="password" name="password" required placeholder="حداقل ۶ کاراکتر">
            </div>
            <div class="form-group">
                <label for="password_confirm">تکرار رمز عبور</label>
                <input type="password" autocomplete="new-password" id="password_confirm" name="password_confirm" required placeholder="رمز عبور را دوباره وارد کنید">
            </div>
            <button type="submit" class="btn btn-primary btn-block">ساخت حساب مدیر</button>
        </form>
    </div>
</body>
</html>
