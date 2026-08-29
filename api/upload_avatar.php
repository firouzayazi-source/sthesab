<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::initSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedIn()) { jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405); }
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

// ستون avatar با migration_p4 می‌آید. این را همین اول بررسی می‌کنیم تا
// اگر نیست، فایل بی‌جهت آپلود و روی دیسک نوشته نشود و بعد دور انداخته
// شود — و کاربر پیام درست بگیرد نه یک خطای عمومی.
if (!usersHaveColumn(Database::getConnection(), 'avatar')) {
    jsonResponse([
        'success' => false,
        'message' => 'ستون تصویر هنوز در دیتابیس ساخته نشده. روی سرور اجرا کنید:  bash deploy/migrate.sh --apply',
    ], 500);
}

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $code = $_FILES['avatar']['error'] ?? -1;
    $msg = ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE)
        ? 'حجم فایل بیش از حد مجاز سرور است.'
        : 'تصویری دریافت نشد.';
    jsonResponse(['success' => false, 'message' => $msg], 422);
}

$file = $_FILES['avatar'];

// ورودی تا ۸ مگابایت پذیرفته می‌شود؛ خروجی همیشه بندانگشتی کوچک است
if ($file['size'] > 8 * 1024 * 1024) {
    jsonResponse(['success' => false, 'message' => 'حجم تصویر نباید بیشتر از ۸ مگابایت باشد.'], 422);
}

// نوع واقعی فایل از محتوا خوانده می‌شود، نه از پسوند
$info = @getimagesize($file['tmp_name']);
if ($info === false) {
    jsonResponse(['success' => false, 'message' => 'فایل انتخابی تصویر معتبری نیست.'], 422);
}

$srcW = (int)$info[0];
$srcH = (int)$info[1];
$mime = $info['mime'] ?? '';

$supported = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $supported, true)) {
    jsonResponse(['success' => false, 'message' => 'فقط JPG، PNG یا WEBP پذیرفته می‌شود.'], 422);
}

if ($srcW < 40 || $srcH < 40) {
    jsonResponse(['success' => false, 'message' => 'تصویر بیش از حد کوچک است.'], 422);
}

$avatarDir = __DIR__ . '/../uploads/avatars';
if (!is_dir($avatarDir)) {
    if (!@mkdir($avatarDir, 0755, true) && !is_dir($avatarDir)) {
        jsonResponse(['success' => false, 'message' => 'پوشه‌ی تصاویر ساخته نشد. دسترسی نوشتن را بررسی کنید.'], 500);
    }
}

// جلوگیری از اجرای هر کدی در پوشه‌ی آپلود
$guard = __DIR__ . '/../uploads/.htaccess';
if (!is_file($guard)) {
    @file_put_contents($guard, "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl)$\">\n    Require all denied\n</FilesMatch>\n");
}

try {
    $newName = 'u' . $userId . '_' . bin2hex(random_bytes(10)) . '.jpg';
} catch (Exception $e) {
    $newName = 'u' . $userId . '_' . uniqid('', true) . '.jpg';
}
$target = $avatarDir . '/' . $newName;

$size = 256; // بندانگشتی مربعی — برای نمایش ۹۶ پیکسلی روی صفحه‌ی رتینا کافی است

$hasGd = function_exists('imagecreatetruecolor') && function_exists('imagejpeg');

if ($hasGd) {
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case 'image/png':  $src = @imagecreatefrompng($file['tmp_name']);  break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false; break;
        default: $src = false;
    }

    if ($src === false) {
        jsonResponse(['success' => false, 'message' => 'خواندن تصویر ممکن نشد. فرمت دیگری را امتحان کنید.'], 422);
    }

    // برش مربعی از مرکز تصویر، سپس کوچک‌سازی
    $side = min($srcW, $srcH);
    $sx = (int)(($srcW - $side) / 2);
    $sy = (int)(($srcH - $side) / 2);

    $dst = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $size, $size, $white); // پس‌زمینه برای PNG شفاف

    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);

    $ok = imagejpeg($dst, $target, 82);

    imagedestroy($src);
    imagedestroy($dst);

    if (!$ok) {
        jsonResponse(['success' => false, 'message' => 'ذخیره تصویر ناموفق بود.'], 500);
    }
} else {
    // بدون GD نمی‌توان کوچک کرد؛ فقط تصاویر کوچک را می‌پذیریم تا سرعت افت نکند
    if ($file['size'] > 400 * 1024) {
        jsonResponse([
            'success' => false,
            'message' => 'روی این سرور امکان کوچک‌سازی تصویر نیست. تصویری کمتر از ۴۰۰ کیلوبایت انتخاب کنید.',
        ], 422);
    }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        jsonResponse(['success' => false, 'message' => 'ذخیره تصویر ناموفق بود.'], 500);
    }
}

@chmod($target, 0644);

$pdo = Database::getConnection();

try {
    // تصویر قبلی پاک شود تا فایل یتیم روی هاست نماند
    $old = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
    $old->execute(['id' => $userId]);
    $oldName = $old->fetch()['avatar'] ?? null;

    $upd = $pdo->prepare('UPDATE users SET avatar = :a WHERE id = :id');
    $upd->execute(['a' => $newName, 'id' => $userId]);

    if ($oldName) {
        $oldPath = $avatarDir . '/' . basename($oldName);
        if (is_file($oldPath)) { @unlink($oldPath); }
    }

    $_SESSION['avatar'] = $newName;

    jsonResponse([
        'success' => true,
        'avatar_url' => APP_BASE_PATH . '/uploads/avatars/' . $newName,
        'message' => 'تصویر پروفایل بروزرسانی شد.',
    ]);
} catch (PDOException $e) {
    // پیام قبلی هر خطای دیتابیسی را «migration را اجرا کنید» می‌خواند،
    // حتی وقتی ستون وجود داشت و مشکل چیز دیگری بود. حالا نبودِ ستون
    // بالاتر و صریح بررسی می‌شود، پس اینجا واقعاً خطای غیرمنتظره است.
    @unlink($target);
    error_log('Upload Avatar Error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'ذخیره در دیتابیس ناموفق بود.'], 500);
}
