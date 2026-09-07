<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId   = Auth::userId();
$fullName = trim(postParam('full_name'));
$username = trim(postParam('username'));
$email    = trim(postParam('email'));
$phone    = trim(postParam('phone'));
$current  = postParam('current_password');

$errors = [];
if ($fullName === '' || mb_strlen($fullName) > 100) { $errors[] = 'نام نمایشی الزامی است.'; }
// ⛔ از قاعده‌ی مشترک رد می‌شود، نه یک الگوی محلی. نسخه‌ی قبلی اینجا
//    نقطه را رد می‌کرد در حالی که پنل مدیر آن را می‌سازد — پس کاربری
//    با نامِ `ali.k` هر بار «نام کاربری معتبر نیست» می‌گرفت بی‌آنکه
//    نامش را عوض کرده باشد، و ایمیل و شماره‌اش هم ذخیره نمی‌شد.
require_once __DIR__ . '/../includes/signup.php';
if (($usernameErr = usernameRuleError($username)) !== '') { $errors[] = $usernameErr; }
if ($current === '') { $errors[] = 'برای تأیید، رمز فعلی را وارد کنید.'; }
// ایمیل اجباری است. کاربران قدیمی که هنوز ایمیل ندارند با اولین
// ویرایش پروفایل مجبور می‌شوند یکی ثبت کنند — و همان چیزی است که
// بازیابی رمز به آن نیاز دارد.
if ($email === '') {
    $errors[] = 'ایمیل الزامی است — بدون آن امکان بازیابی رمز وجود ندارد.';
} elseif (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'ایمیل معتبر نیست.';
}

if (!empty($errors)) { jsonResponse(['success' => false, 'message' => implode(' ', $errors)], 422); }

$pdo = Database::getConnection();

$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    jsonResponse(['success' => false, 'message' => 'رمز فعلی اشتباه است.'], 403);
}

// نام کاربری نباید تکراری باشد
$dup = $pdo->prepare('SELECT id FROM users WHERE username = :un AND id != :id');
$dup->execute(['un' => $username, 'id' => $userId]);
if ($dup->fetch()) {
    jsonResponse(['success' => false, 'message' => 'این نام کاربری قبلاً استفاده شده است.'], 422);
}

// ستون email با migration_password_reset اضافه شده؛ اگر هنوز اجرا نشده
// باشد، بقیه‌ی صفحه باید کار کند و فقط ایمیل نادیده گرفته شود.
$hasEmail = usersHaveEmailColumn($pdo);

try {
    // ایمیل اول نوشته می‌شود: اگر تکراری بود باید کل ذخیره رد شود، نه
    // اینکه نام عوض شود و ایمیل بی‌سروصدا جا بماند.
    // saveUserEmail هرگز ایمیل را پاک نمی‌کند؛ اینجا هم $email حتماً
    // پر است چون بالاتر الزامی شده.
    if ($hasEmail) {
        $emailErr = saveUserEmail($pdo, $userId, $email);
        if ($emailErr !== '') {
            jsonResponse(['success' => false, 'message' => $emailErr], 422);
        }
    }

    // ⛔ شماره موبایل هم پیش از نامِ نمایشی نوشته می‌شود، به همان دلیلِ
    //    ایمیل: اگر تکراری یا نامعتبر بود کلِ ذخیره باید رد شود، نه اینکه
    //    نام عوض شود و شماره بی‌سروصدا جا بماند.
    // ⚠ شماره **اختیاری** است، برخلاف ایمیل. کاربری که ورودِ پیامکی
    //   نمی‌خواهد نباید مجبور شود شماره‌اش را بدهد؛ خالی گذاشتنش هم
    //   شماره‌ی قبلی را پاک نمی‌کند (قاعده‌ی `saveUserPhone`).
    if (tableHasColumn('users', 'phone')) {
        $phoneErr = saveUserPhone($pdo, $userId, $phone);
        if ($phoneErr !== '') {
            jsonResponse(['success' => false, 'message' => $phoneErr], 422);
        }
    }

    $upd = $pdo->prepare('UPDATE users SET full_name = :fn, username = :un WHERE id = :id');
    $upd->execute(['fn' => $fullName, 'un' => $username, 'id' => $userId]);

    $_SESSION['full_name'] = $fullName;
    $_SESSION['username']  = $username;
    Auth::rememberUsername($username);

    jsonResponse(['success' => true, 'message' => 'اطلاعات بروزرسانی شد.']);
} catch (PDOException $e) {
    error_log('Update Profile Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطایی رخ داد.'], 500);
}
