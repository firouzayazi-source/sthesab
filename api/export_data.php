<?php
/**
 * خروجی کاملِ داده‌ی کاربر، به‌صورت یک فایل JSON.
 *
 * ⛔ POST است نه GET، با CSRF — با اینکه چیزی نمی‌نویسد. دلیلش این است
 *    که یک لینکِ GET را می‌شود در `<img src>` یا یک صفحه‌ی دیگر جاسازی
 *    کرد و مرورگرِ کاربرِ واردشده خودش صدایش می‌زند؛ اینجا خروجی
 *    **همه‌ی** داده‌ی اوست، پس ارزشِ سخت‌گیری را دارد.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_data.php';

Auth::initSession();

if (!Auth::isLoggedIn()) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'ابتدا وارد شوید.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'درخواست نامعتبر است.'], 405);
}
Csrf::verifyOrFail(postParam('csrf_token'));

$userId = Auth::userId();

try {
    $data = exportUserData($userId);
} catch (Throwable $e) {
    error_log('Export Data Error: ' . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'خروجی گرفته نشد.'], 500);
}

if (!$data) {
    header('Content-Type: application/json; charset=utf-8');
    jsonResponse(['success' => false, 'message' => 'کاربر یافت نشد.'], 404);
}

$json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

// ⚠ نامِ فایل تاریخِ شمسی می‌گیرد چون کاربر با همان تاریخ فکر می‌کند،
//   ولی با **ارقام لاتین**: یک نامِ حاوی ارقامِ فارسی در سرآیندِ
//   `filename=` غیرمجاز است و مرورگر بی‌سروصدا کنارش می‌گذارد و فایل
//   را «download» ذخیره می‌کند. با کروم آزموده و دیده شد.
//   `/` هم به `-` تبدیل می‌شود تا روی ویندوز باز شود.
$name = 'daftar-mali-' . str_replace('/', '-', toLatinDigits(toJalali(date('Y-m-d')))) . '.json';

// ⛔ گزیپِ خروجی که در db.php روشن شده باید اینجا خاموش شود، وگرنه
//    مرورگر فایل را دوبار فشرده می‌گیرد و چیزی که ذخیره می‌شود قابل
//    باز کردن نیست.
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
header_remove('Content-Encoding');

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($json));
header('Cache-Control: no-store, private');

echo $json;
